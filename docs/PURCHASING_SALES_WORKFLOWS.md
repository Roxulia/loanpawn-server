# Purchasing and Sales orchestration workflows

This supplement lists the main service methods and their implementation order. Steps describe the current code paths in `app/Services/TenantModule`. Public API requests are validated by their request/controller layer before these services run; the service-level checks below are the additional business rules visible in the orchestration methods.

## Purchasing

### Supplier create/update — `PurchaseSupplierService::create(...)` / `update(...)`

1. Resolve tenant context and normalize supplier identity/contact data.
2. Validate required values and tenant-scoped uniqueness.
3. For create, reserve an optional idempotency key and replay an already completed result when present.
4. Persist the supplier record.
5. Write the operation audit when enabled.
6. Complete the idempotency record and return the supplier resource.
7. `PurchasingService::quickPurchase(...)` may call supplier creation for an inline new supplier with nested operation auditing suppressed; the outer quick-purchase flow writes its completion audit.

### Create purchase order — `PurchasingService::createOrder(array $data, ?string $idempotencyKey)`

1. Resolve the current tenant and active supplier by business code; reject missing or inactive suppliers.
2. Uppercase the currency code and require an active tenant-visible currency.
3. Create a `DRAFT` order with a generated code, supplier, currency, note, and creator.
4. For each submitted item, resolve the optional catalog item and reject an unavailable catalog reference.
5. Use the catalog tracking mode when catalog-linked; otherwise use the submitted tracking mode.
6. Validate quantity according to tracking mode.
7. Snapshot description, tracking mode, unit label/code, ordered quantity, and unit price into an order line. A catalog item is optional for unique goods.
8. Write the order-created operation audit.
9. Return the order with its item lines; the idempotency wrapper stores the result and makes retries replay-safe.

### Edit draft order — `PurchasingService::updateOrder(string $code, array $data)`

1. Resolve tenant context, begin a transaction, and lock the purchase order.
2. Require `DRAFT` status; reject changes after the purchase workflow has started.
3. Resolve an active replacement supplier and validate the new tenant-visible currency.
4. Update supplier, currency, and note; remove the old draft lines.
5. For each replacement item, resolve optional catalog item, select tracking mode, and validate quantity.
6. Recreate line snapshots with new codes, descriptions, units, quantities, and prices.
7. Write the order-updated audit and return reloaded order details.
8. If validation or persistence fails, roll back the header and line changes together.

### Change order state — `PurchasingService::transition(string $code, string $action, ?string $idempotencyKey)`

1. Resolve the tenant order under a row lock inside the idempotent transaction.
2. Allow `order` only from `DRAFT` to `ORDERED`.
3. Allow `confirm` only from `ORDERED` to `CONFIRMED`.
4. Allow `cancel` only from `DRAFT` or `ORDERED` to `CANCELLED`.
5. Before cancellation, check cumulative received quantity and reject cancellation if anything was received.
6. Persist the new state and set `ordered_at` when the order becomes `ORDERED`.
7. Write a status-change audit and return refreshed order details.

### Record a pre-receipt supplier advance — `PurchasingService::recordPayment(string $orderCode, array $data, ?string $idempotencyKey)`

1. Resolve and lock the tenant order; reject payment while it is `DRAFT` or `CANCELLED`.
2. Reject this advance workflow if any goods have been received; later payable installments use `SupplierPayableService::pay(...)`.
3. Resolve the active tenant financial account and require its currency to match the order currency.
4. Calculate order total and existing net payments (payments less refunds); reject an advance above the remaining order total.
5. Create the purchase payment with date, amount, account, reference, note, and creator.
6. Record the payment as an Asset accounting transaction representing a supplier advance.
7. Attach the accounting transaction to the payment and record the account cash outflow.
8. Write a payment-created audit and return payment detail under idempotency protection.

### Refund a pre-receipt advance — `PurchasingService::recordRefund(string $paymentCode, array $data, ?string $idempotencyKey)`

1. Resolve and lock the tenant payment; reject a missing payment.
2. Reject refund through this method if the order has received goods; received-purchase settlements use supplier payable/credit handling.
3. Sum prior refunds and reject a refund larger than the unrefunded payment amount.
4. Require the original payment account and resolve it as an active account.
5. Create the refund in the original account and order currency.
6. Record an Asset accounting inflow and corresponding account balance increase.
7. Attach the accounting transaction, write the refund audit, and return refreshed payment detail.

### Receive goods — `PurchasingService::recordReceipt(string $orderCode, array $data, ?string $idempotencyKey)`

1. Enter the idempotent transaction; resolve and lock the tenant order.
2. Require order status `CONFIRMED`.
3. Lock the supplier to serialize payable and supplier-credit allocation.
4. For every submitted line, resolve and lock its order line and verify it belongs to the selected order.
5. Validate each quantity against tracking mode and ensure prior receipts plus this receipt do not exceed ordered quantity.
6. Load the order line's optional Catalog item and any linked Inventory item.
7. Create the receipt header and write the receipt-created audit.
8. Create each receipt line first so it can serve as the stable source reference for movements, Ownership lots, and accounting.
9. Build the Inventory receipt data from destination location, quantity, tracking mode, unit, description, optional Catalog code, serialized identifiers, and receipt-line source code.
10. For non-`UNIQUE` tracking, reuse the order line's existing Inventory item code when present. Unique goods can receive distinct Inventory identities.
11. Call `InventoryService::receive(...)`. This creates or updates the Inventory item, adds quantity at the selected location, and creates serialized units from supplied identifiers where applicable.
12. Resolve the resulting Inventory item. If this is the first non-unique receipt, save the Inventory item ID on the purchase order line for reuse on later partial receipts.
13. Call `OwnershipService::acquireFromSource(...)` using source module `PURCHASING` and source type `PurchaseReceiptLine`, with the received quantity/date, purchase unit price as cost basis and estimated value, order currency, and item description.
14. Save the resulting owned-item code and acquisition-lot code, Inventory item ID, serialized unit codes, and received value on the receipt line.
15. Record an internal Asset accounting entry for the received goods. This recognizes inventory value without recording cash received or paid.
16. Sum this receipt's value and calculate net supplier advances after refunds and value assigned to previous receipts.
17. Apply the remaining advance to this receipt, then call `SupplierPayableService::createForReceipt(...)` for unpaid receipt value; the payable service applies available supplier credits.
18. Save the payable code and credit-applied amount on the receipt, then return receipt detail.
19. Commit the order, receipt, Inventory, Ownership, accounting, and payable changes together. On retry, idempotency replays the existing result; on failure, the transaction rolls back.

### Settle a supplier payable — `SupplierPayableService::pay(string $payableCode, array $data, ?string $idempotencyKey)`

1. Resolve and lock the tenant receipt-linked payable; verify it is open and the requested amount is positive and within the outstanding balance.
2. Resolve an active financial account in the payable currency and validate the payment data.
3. Reserve/replay idempotency and begin the payment transaction.
4. Create the payable payment and reduce the unpaid liability balance.
5. Record the payable liability reduction in Accounting and the cash outflow in the Financial Account ledger.
6. Persist the accounting/account references and return the updated payable/payment result.
7. Supplier credits from returns are applied through `SupplierPayableService::applyReturnCredit(...)`; applying a credit is not a cash movement.

### Return received goods — `PurchasingService::recordReturn(string $orderCode, array $data, ?string $idempotencyKey)`

1. Enter the idempotent transaction; resolve and lock the tenant purchase order and supplier.
2. For each return item, lock the source receipt line and verify its order line belongs to this order.
3. Validate tracking-mode quantity and ensure cumulative returned quantity does not exceed received quantity.
4. For serialized items, require one distinct unit code per returned unit; each unit must be on the source receipt and not previously returned.
5. Create the return header and audit record; create each return line before stock/ownership changes to establish a stable source code.
6. Call `InventoryService::issue(...)` at the specified current location with the return-line source. This reduces on-hand quantity and issues the selected serialized units.
7. Call `OwnershipService::reduceForPurchaseReturn(...)` against the exact owned item and acquisition lot created by that receipt.
8. Calculate return value using the order line's snapshotted unit price; validate cash refund is between zero and the return value.
9. If cash refund is positive, resolve an active account with matching currency, record an incoming Asset accounting transaction, and increase account balance.
10. Treat the rest as non-cash supplier credit and call `SupplierPayableService::applyReturnCredit(...)` to reduce the receipt payable and preserve excess credit for later receipts.
11. Record an internal Asset reversal for returned inventory and persist cash, credit, payable, and accounting references on the return line.
12. Return return detail; idempotency keeps Inventory, Ownership, supplier settlement, and accounting changes atomic.

### One-step purchase — `PurchasingService::quickPurchase(array $data, ?string $idempotencyKey)`

1. Start the outer idempotent transaction and suppress duplicate nested operation audits.
2. Use the selected supplier or create a new supplier inline.
3. Create a draft purchase order and lines through `createOrder(...)`.
4. Transition the order to `ORDERED`, then `CONFIRMED`.
5. If tender is positive, create a pre-receipt advance through `recordPayment(...)`.
6. Convert each ordered line to a full-quantity receipt line, preserving serialized unit identifiers.
7. Call `recordReceipt(...)` using purchase date and selected location; this is where Inventory quantity, Ownership lots, inventory asset value, and any supplier payable are created.
8. Write the quick-purchase completion audit and return order, optional payment, and receipt resources.

### Purchasing reads — `PurchasingService::orders/order/payments/receipts/returns(...)` and `SupplierPayableService::list(...)`

1. Resolve the current tenant and requested business code/filter.
2. Load tenant-scoped orders and related lines, receipts, payments, returns, and payable references.
3. Shape resources with ordered/received/returned quantities and supplier balances.
4. Return results without creating stock, Ownership, or accounting entries.

## Sales

### Create sale draft — `SalesService::createOrder(array $data, ?string $idempotencyKey)`

1. Start the idempotent operation and resolve the active tenant-visible currency.
2. Resolve an optional customer; allow no customer for walk-in sales, but reject an invalid supplied customer code.
3. Create a `DRAFT` order with generated code, date, currency, note, optional customer, and creator.
4. For each line, resolve its Inventory item, owned item, and FIFO acquisition lots.
5. Verify the owned item is linked to the same Inventory item and at least one lot is in the sale currency.
6. Validate quantity against tracking mode and create a line snapshot containing item description, tracking mode, unit, quantity, and unit price.
7. Write the create audit and return order detail. Draft creation has no stock reservation, Ownership reduction, or financial posting.

### Edit draft — `SalesService::updateDraftOrder(string $code, array $data, ?string $idempotencyKey)`

1. Start idempotency handling, begin a transaction, and lock the tenant order.
2. Permit editing only for an untouched draft; reject orders with downstream reservations/payments/deliveries.
3. Resolve and validate replacement currency and optional customer.
4. Update the order header and delete existing draft lines.
5. Re-resolve each Inventory item and owned item; verify they are linked and a same-currency lot exists.
6. Validate tracking quantity and recreate line snapshots.
7. Write the update audit and return refreshed order detail; failures roll back header and lines.

### Confirm or cancel — `SalesService::transition(...)` → `reserveLine(...)`

1. Start the idempotent transaction and lock the tenant order.
2. For `confirm`, require `DRAFT` status and process each order line through `reserveLine(...)`.
3. In `reserveLine(...)`, read Inventory availability by location and Ownership lots in FIFO order.
4. Intersect physical on-hand quantity and unpledged/unreserved owned quantity by location and lot; reject insufficient sellable quantity.
5. For serialized goods, select concrete available Inventory unit codes.
6. Create Inventory reservations and Ownership lot reservations, then persist reservation rows linking lot, location, item, and quantity.
7. Mark the order `CONFIRMED` and write status audit.
8. For `cancel`, allow only a confirmed order with no delivered quantity.
9. Release each Inventory reservation and corresponding Ownership reservation; mark reservation rows released and set order `CANCELLED`.
10. Return refreshed order details; invalid transitions roll back.

### Record payment/deposit — `SalesService::recordPayment(...)`

1. Start the idempotency wrapper, lock the order, and require `CONFIRMED` or `COMPLETED` status.
2. Resolve the active payment account and require currency to match the sale.
3. Calculate order total, net prior prepayments, and collections already applied to sale receivables.
4. Calculate remaining amount due; reject if no balance remains.
5. Treat the submitted amount as tender, apply only up to the remaining amount, and calculate excess as change.
6. If sale receivables are outstanding, allocate the applied amount to receivable collection first through `SaleReceivableService::collectForOrder(...)`.
7. Treat remaining applied value as customer deposit; store applied amount, gross tender, and change separately on the sale payment.
8. Post the customer deposit as a Liability accounting entry and record gross cash received in the Financial Account ledger.
9. If change is positive, record a separate outgoing Asset/account adjustment for change.
10. Write payment/receivable audit records and return refreshed order detail.

### Collect sale receivable — `SalesService::recordReceivablePayment(...)` → `SaleReceivableService::collect(...)`

1. Start idempotency handling and resolve the tenant receivable by business code.
2. Resolve the active account and validate order currency.
3. Lock the receivable and calculate outstanding balance; reject invalid or excessive collection.
4. Split tender into amount applied to receivable and change returned.
5. Create the receivable payment and reduce the outstanding receivable.
6. Record cash/account movement, reduce receivable Asset in Accounting, and post change separately if due.
7. Write collection audit and return updated receivable details.

### Record partial delivery — `SalesService::recordDelivery(...)`

1. Start idempotency handling, begin a transaction, and lock the tenant order.
2. Require `CONFIRMED` status and resolve the selected Delivery record.
3. For each submitted line, verify it belongs to the order, validate tracking quantity, and ensure delivery quantity is within active reservation quantity.
4. Consume reservations FIFO and create delivery allocations preserving lot, location, cost, sale price, and serialized unit codes.
5. Calculate allocation cost from the acquisition lot's snapshotted unit cost.
6. Post COGS as Expense and inventory Asset relief in Accounting; attach those transaction IDs to the allocation.
7. Call `InventoryService::issue(...)` using location and reservation reference to reduce stock.
8. Release the corresponding Ownership reservation and reduce Ownership from the exact lot through `OwnershipService::reduceForSale(...)`.
9. Reduce/consume reservation quantity and serialized unit codes.
10. Recognize delivery fees and calculate delivered value.
11. Apply available customer deposits to delivered value; create a sale receivable for the unpaid delivered balance.
12. When deposits are recognized, post Liability reduction and Revenue for the deposit applied.
13. Mark order `COMPLETED` when delivered quantity reaches ordered quantity; return refreshed order detail.

### Immediate counter sale — `SalesService::quickSale(...)` → `createOrder(...)` → `transition(...)` → optional `recordPayment(...)` → `completeImmediately(...)`

1. Start an outer idempotent transaction and suppress duplicate nested operation audits.
2. Create a draft order and order lines.
3. Confirm the order, reserving Inventory and Ownership quantity.
4. If tender is positive, record payment/deposit and change.
5. In `completeImmediately(...)`, lock the confirmed order and create an immediate sale delivery record.
6. For each reservation, create delivery allocation with acquisition lot, location, unit cost, unit price, and serialized unit codes.
7. Post COGS Expense and inventory Asset relief.
8. Issue Inventory, release Ownership reservation, reduce Ownership from the sold lot, and consume reservation rows.
9. Apply prepayments to delivered value and create a receivable for unpaid value.
10. Post deposit Liability release, receivable Asset, and sale Revenue where applicable.
11. Mark the sale `COMPLETED`, write completion audit, and return refreshed sale details.

### Return delivered goods — `SaleReturnService::create(...)`

1. Start idempotency handling and transaction; resolve and lock the tenant sale order.
2. Reject `DRAFT` or `CANCELLED` orders; each return must reference a delivered allocation.
3. Create the return header.
4. For each return item, lock the delivery allocation and verify it belongs to the selected sale.
5. Ensure return quantity does not exceed delivered allocation quantity less prior returns.
6. Resolve the return Inventory location; require a sellable location for `RESTOCK` and a non-sellable location for `QUARANTINE`.
7. For serialized goods, require whole-unit quantity and select only units delivered on this allocation and not already returned.
8. Create the return line and call `InventoryService::receiveSaleReturn(...)` to restore stock and serialized-unit custody at the selected location.
9. Call `OwnershipService::restoreForSaleReturn(...)` against the original owned item and acquisition lot.
10. Reverse COGS and restore Inventory Asset using delivery allocation unit cost.
11. For quarantine disposition, additionally record the cost write-off as Expense and Asset reduction.
12. Apply return value as credit against the outstanding receivable first.
13. Refund any remainder from prior sale payments; require a matching-currency active account and prevent total refunds exceeding received payments.
14. Record each refund as outgoing Asset accounting and financial-account adjustment; save line credit/refund amounts.
15. Aggregate return totals, write `sales.return.created` audit, and return return detail. All Inventory, Ownership, Accounting, receivable, and refund changes roll back together on failure.

### Sales reads — `SalesService::orders/order(...)`, `SaleReturnService::returns(...)`, `SaleReceivableService::list/detail(...)`

1. Resolve the current tenant and requested order/return/receivable code or search filter.
2. Load tenant-scoped order lines, reservations, deliveries, allocations, returns, payments, and receivables as applicable.
3. Shape quantities and totals, including delivered value, collected amounts, remaining balances, and return history.
4. Return resources without changing Inventory, Ownership, or financial state.

## Receipt integration clarification

The current `PurchasingService::recordReceipt(...)` implementation explicitly calls both `InventoryService::receive(...)` and `OwnershipService::acquireFromSource(...)`. Therefore, receipt-to-Inventory and receipt-to-Ownership orchestration exists in source. If a tenant's receipt is not increasing those balances at runtime, inspect the configured feature entitlements and server route middleware, selected location, tracking mode/identifiers, and the receipt response/error before treating it as a missing service integration.

