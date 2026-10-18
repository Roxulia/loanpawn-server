# Implemented modules, processes, and linked workflows

This inventory is based on the current frontend routes and screens, tenant and platform controllers/services, and `lonepawn-server/config/package_features.php`. “Individual processes” means actions available within a module. “Linked implementation” describes the records and modules the code coordinates. Access also depends on the signed-in user's permissions; package gates are listed separately.

## Tenant application

### Pawn operations

#### Pawn slip / loan contract
- **Individual processes:** list and inspect slips; create a contract; update or delete where allowed; explicitly change slip status; view current balance and collateral; preview/download the slip document; maintain expiration state.
- **Linked implementation:** slip creation ties a customer and one or more collateral items to principal, interest terms, currency, dates, and the originating financial account. Interest schedule rows are generated from the contract terms. Initial disbursement is recorded in accounting and account balance history. Explicit status changes run transactionally, recalculate the customer's trust score, and invalidate the slip-list cache. Expiration processing updates the slip lifecycle. Document generation renders contract and collateral details with tenant branding/layout. Other material operations use the system's operation/audit logging where implemented.
- **Feature gates:** `loan_contract_management`; `slip_document_preview` for preview/download; `slip_document_layout_management` for custom header/footer layouts. The latter is inactive by default and assigned to Premium in the current configuration.
- **Permission examples:** `list_loan_contract`, `create_loan_contract`, `update_loan_contract`, `delete_loan_contract` (plus document-specific permissions where enforced).

#### Collateral
- **Individual processes:** register collateral, list/search and inspect items, update/delete eligible items, pack multiple items, and track collateral state/custody.
- **Linked implementation:** collateral records belong to a customer and may be attached to a pawn slip. When inventory is enabled, pawn collateral receipt can create or update an inventory item and custody/location record. Slip redemption or expiry affects the collateral lifecycle; ownership transfer from expired collateral is handled in Ownership.
- **Feature gates:** `collateral_management`; inventory-linked functions also require `inventory_management`.
- **Permission examples:** `list_collateral`, `create_collateral`, `update_collateral`, `delete_collateral`.

#### Interest payments
- **Individual processes:** calculate due interest, show payment history, accept interest payments, refresh schedules, and apply renewal/compounding or partial-principal rules where enabled.
- **Linked implementation:** payment rows reference the loan slip; amounts and dates update the outstanding interest/principal position. Receipts create accounting transactions against a selected financial account, record exchange-rate/reporting values when relevant, and log changes. Idempotency protects payment submissions from duplicate processing. Unpaid interest can be carried/compounded into debt according to the configured process.
- **Feature gates:** `interest_payment_management`; `advanced_interest_process` for compounding and partial principal collection (Premium in package configuration).
- **Permission examples:** interest list/accept/update permissions.

#### Redemption
- **Individual processes:** calculate redemption amount, preview amount due, accept redemption, and inspect redemption history.
- **Linked implementation:** redemption settles the slip's principal and due interest, creates a redemption record and accounting receipt, closes the contract, and updates collateral availability/custody. The transaction is associated with its account and audit history.
- **Feature gates:** `redemption_management`.
- **Permission examples:** redemption calculation and acceptance permissions.

### Customer management
- **Individual processes:** create, list/search, view, update, and delete customers; inspect customer-related pawn and trust information.
- **Linked implementation:** customers are referenced by pawn slips, collateral, customer debts/receivables, and trust-score calculations. Reuse/duplicate checks are applied during customer creation; changes are tenant-scoped and auditable.
- **Feature gates:** `customer_management`.
- **Permission examples:** `list_customer`, `create_customer`, `update_customer`, `delete_customer`.

### Accounting and financial accounts

#### Accounting records and reports
- **Individual processes:** review accounting transactions and summaries; filter/history views; manage accounting day operations; inspect reporting-currency totals and transaction details.
- **Linked implementation:** pawn disbursements, interest, redemptions, expenses, capital, debts, business-loan payments, purchase/sales cash flows, and account transfers write accounting entries. Entries reference their source operation, account, currency, and exchange-rate snapshot. Accounting day eligibility and business timezone affect date assignment. Repair/backfill services address legacy accounting links.
- **Feature gates:** `accounting_management`; `tenant_timezone_management` for business timezone; `automatic_open_close` for scheduled weekly day open/close.
- **Permission examples:** `list_accounting`, accounting day permissions.

#### Financial accounts
- **Individual processes:** list, create, inspect, update accounts; assign account access to staff; transfer funds between accounts.
- **Linked implementation:** accounts store operational balances and are selected by receipts/payments across pawn, expense, capital, debt, business loans, purchasing, and sales. Transfers create paired movements and accounting entries, with currency/exchange-rate and fee handling where applicable. Account type and staff assignment constrain usage.
- **Feature gates:** `multi_account_management` for account management; `account_transferable` for transfers; `accounting_type_management` for tenant-owned account types. Package account-count limits apply.
- **Permission examples:** `list_financial_account`, `create_financial_account`, `update_financial_account`, `transfer_financial_account`.

#### Currencies and exchange rates
- **Individual processes:** manage tenant currencies and base/reporting currency settings; manage exchange pairs; assign daily rates; view rate history/trends; configure reporting-currency rates and recalculate historical values.
- **Linked implementation:** rates are consumed by cross-currency transactions and reporting. Transaction-time rates are stored with financial entries; historical recalculation can update reporting amounts and notify tenant users. Currency, pair, and rate counts are package-limited.
- **Feature gates:** `currency_management`, `exchange_pair_management`, and `daily_rate_assignment`; these are primarily included in Basic and Premium packages. Reporting rate screens require the relevant currency/rate permissions.

### Expense and capital

#### Expenses
- **Individual processes:** create, list, inspect, update, and delete expense records; attach supporting details/images where available; record scheduled expense configurations and occurrences.
- **Linked implementation:** an expense payment decreases the selected account and creates an accounting transaction. Scheduled expenses create expense occurrences and invoke the same expense/accounting path. Edits/deletions repair or reverse linked accounting entries as permitted.
- **Feature gates:** `expense_management`; `scheduled_expense_management` for recurring/one-time schedules and automatic payment.
- **Permission examples:** expense CRUD and scheduled-expense CRUD permissions.

#### Capital
- **Individual processes:** record capital contributions/withdrawals, list and inspect entries, update or delete eligible records.
- **Linked implementation:** entries affect the selected financial account and create categorized accounting records; changes update/reverse the linked transaction and are logged.
- **Feature gates:** `capital_management`.
- **Permission examples:** `list_capital`, `create_capital`, `update_capital`, `delete_capital`.

### Debt and lender obligations

#### Debt and receivables
- **Individual processes:** create internal or external debt, list and inspect debt, calculate interest, record principal/interest payments, view payment history, update compounding schedules, and manage sale receivables.
- **Linked implementation:** debt payments update remaining balances and create account/accounting movements. Internal debt links can represent movement between business accounts; sale receivables connect a sale's unpaid amount to customer debt collection. Interest rules and compound schedules determine due amounts.
- **Feature gates:** `debt_management`; `advanced_interest_process` for compound/advanced interest behavior. Sale receivables additionally depend on Sales being available.
- **Permission examples:** `list_debt`, `create_debt`, `update_debt`, `delete_debt`.

#### Business loans and lenders
- **Individual processes:** manage lender identities; create, list, inspect, update, and delete business loans; record principal/interest repayments; update compound schedules; view upcoming payables/obligations.
- **Linked implementation:** loans reference lenders and receipt accounts. Receiving loan funds and paying principal/interest create account and accounting transactions. Obligation views combine business-loan payables with purchase supplier payables.
- **Feature gates:** `business_loan_management` and `lender_management`; `advanced_interest_process` for compound schedules.
- **Permission examples:** business-loan CRUD/payment and lender management permissions.

### Catalog, inventory, ownership, purchasing, sales, delivery

#### Catalog and master data
- **Individual processes:** manage reusable catalog items, categories, and units; manage material, interest, expense, and financial-account types.
- **Linked implementation:** catalog items and taxonomy are reused by inventory, purchase lines, and sales lines. Master-data types feed pawn collateral and finance/expense forms.
- **Feature gates:** `catalog_management`; `master_data_management`; `accounting_type_management` for tenant-defined financial account types.
- **Permission examples:** catalog item/category/unit and master-data permissions.

#### Inventory
- **Individual processes:** view stock and item details; receive stock; manage locations; inspect custody and movements; receive returned sale items and pawn collateral.
- **Linked implementation:** inventory movements link an item, quantity, location, and source document. Purchase receipts and sales returns can increase stock; deliveries and sale fulfilment can decrease/assign stock; pawn collateral receipt can register custody. Idempotency prevents repeated receipts.
- **Feature gates:** `inventory_management`.
- **Permission examples:** `list_inventory`, `receive_inventory`, inventory/location management permissions.

#### Ownership
- **Individual processes:** list owned items and acquisition lots; acquire items; view expired collateral; transfer eligible expired collateral into owned inventory; inspect ownership changes.
- **Linked implementation:** an acquisition creates an owned item/lot, cost basis, and inventory representation. Expired pawn collateral can be explicitly acquired under the configured collateral-cost rule, linking the originating slip/collateral to the owned item and its inventory record.
- **Feature gates:** both `ownership_management` and `inventory_management` are required by the frontend routes.
- **Permission examples:** `list_owned_item`, `create_owned_item` and related update permissions.

#### Purchasing and suppliers
- **Individual processes:** manage supplier records; create/edit/list/inspect purchase orders; record receipts, payments, refunds, and returns; review supplier payables.
- **Linked implementation:** orders contain catalog/inventory lines and supplier terms. Receipt updates inventory; payments/refunds affect accounts and accounting; returns adjust stock and supplier balances. Payables are surfaced in the shared Obligations view.
- **Feature gates:** `purchasing_management`.
- **Permission examples:** `list_purchase_order`, `manage_purchase_order`, `list_supplier`, `manage_supplier`.

#### Sales, receivables, and delivery
- **Individual processes:** create/list/edit sales; record payments; process sale returns; manage deliveries and fulfilment; inspect unpaid receivables.
- **Linked implementation:** sale lines use catalog/inventory items; payments create account and accounting movements; unpaid amounts can create customer receivables/debt; returns adjust sales balances and may return items to inventory; delivery records track fulfilment against sales.
- **Feature gates:** `sales_management`; `delivery_management` is referenced by the frontend route but is absent from the current package feature registry and package assignments, so the delivery route is not enabled through the configured package gates as currently written.
- **Permission examples:** `list_sale`, sale management/payment/return permissions, `list_delivery`.

### Dashboard, settings, staff, notifications

#### Dashboard
- **Individual processes:** view tenant summary and finance/operations metrics, with configured financial-unit presentation.
- **Linked implementation:** aggregates accounting and operational data such as pawn, expense, account, and other tenant activity; access is permission-controlled.
- **Feature gates:** `dashboard` (included in Basic and Premium packages, not Trial in the current package arrays).
- **Permission:** `dashboard`.

#### Staff and roles
- **Individual processes:** create, list, inspect, update, deactivate/delete staff; assign roles/permissions and financial-account access; manage role templates.
- **Linked implementation:** tenant users authenticate into tenant context and their permissions guard module actions. Staff notification and activity records support operational updates and audit history. Staff-count package limits apply.
- **Feature gates:** `tenant_user_management`.
- **Permission examples:** `list_user`, `create_user`, `update_user_roles`, delete-user permission.

#### Tenant settings, default data, and documents
- **Individual processes:** update tenant/business settings, personal preferences, finance settings, default data, and document settings; customize slip layout when enabled; configure branding where enabled.
- **Linked implementation:** settings seed/bootstrap defaults used by finance, pawn, timezone, and currency workflows. Document settings feed slip rendering. Tenant branding changes are handled by tenant configuration services.
- **Feature gates:** `master_data_management`; `tenant_timezone_management`; `slip_document_layout_management`; `branding_management` (inactive by default and not assigned in the current packages). `subdomain_available` enables subdomain access.
- **Permission examples:** settings and default-data update permissions.

#### Notifications and audit/activity history
- **Individual processes:** receive tenant notifications, inspect activity/history, mark notification state where supported.
- **Linked implementation:** user notifications are generated by workflows such as reporting-currency recalculation and other tenant events; business services write audit/operation logs around material changes. These are cross-cutting capabilities rather than standalone package-gated business modules.

#### Authentication and app compatibility
- **Individual processes:** tenant login, SSO entry, password/profile management, tenant resolution, license checks, and app-version compatibility notices.
- **Linked implementation:** tenant resolution establishes tenant context before tenant API access; authentication/authorization gates routes; license state can restrict the application; compatibility service surfaces version requirements.
- **Feature gates:** `subdomain_available` for tenant subdomain access; license and compatibility checks are platform/system controls.

## Platform application (separate from tenant package gates)

Platform screens are controlled by platform authentication/authorization, not tenant package feature arrays.

### Platform tenant administration
- **Individual processes:** provision tenants; list/search and inspect tenants; edit tenant settings/contact/subdomain/branding; grant or change licenses and packages; open/manage tenant access; review tenant requests.
- **Linked implementation:** tenant provisioning coordinates tenant identity, initial owner/user, settings/default data, package/license, and tenant database state. Package changes affect tenant module feature availability and resource quotas.

### Plans, packages, feature flags, and billing
- **Individual processes:** manage plans/packages and package flags; review billing records and payment requests; configure payment QR details; issue tickets and manage billing status.
- **Linked implementation:** plan/package feature arrays and quotas determine tenant entitlements. Payment requests connect tenant billing to platform review and status changes.

### Platform users, dashboards, and support
- **Individual processes:** manage platform administrators/users and credentials; view platform dashboard; handle customer-service/support tickets; administer issued tickets.
- **Linked implementation:** platform identity and permissions are separate from tenant users. Support requests associate tenant/customer context with platform staff workflows.

### Platform finance/reference data and integrations
- **Individual processes:** manage global currencies, exchange pairs, and daily rates; administer catalog units; configure platform settings; handle Telegram/webhook notifications and language endpoints.
- **Linked implementation:** global reference data supports platform billing and tenant initialization. Telegram notifications report selected platform events. Platform data is distinct from tenant-owned accounting and currency data.

## Package feature gates at a glance

The current source of truth is `lonepawn-server/config/package_features.php`. A package must include a feature and the feature must be active; a user must also pass the route's permission guard.

| Feature family | Gate keys | Current package placement |
|---|---|---|
| Pawn core | `customer_management`, `collateral_management`, `loan_contract_management`, `interest_payment_management`, `redemption_management` | Trial, Basic, Premium |
| Accounting operations | `accounting_management`, `expense_management`, `capital_management`, `debt_management`, `business_loan_management`, `lender_management`, `account_transferable`, `multi_account_management` | Trial, Basic, Premium (some advanced settings differ) |
| Documents | `slip_document_preview` | Trial, Basic, Premium |
| Master data and dashboard | `master_data_management`, `dashboard` | Basic, Premium |
| Currency | `currency_management`, `exchange_pair_management`, `daily_rate_assignment` | Basic, Premium |
| Premium operations | `slip_document_layout_management`, `automatic_open_close`, `advanced_interest_process`, `scheduled_expense_management`, `subdomain_available` | Premium; layout gate currently globally inactive |
| Inventory and commerce | `catalog_management`, `inventory_management`, `ownership_management`, `purchasing_management`, `sales_management` | Defined as active feature flags but not included in the listed Trial/Basic/Premium feature arrays in this config |
| Branding | `branding_management` | Globally inactive and not assigned to a package |
| Delivery | `delivery_management` | Referenced in a route, but no matching registry entry/package assignment is present |
| Tenant timezone | `tenant_timezone_management` | Basic, Premium |

**Package note:** budgeting packages are separately defined. `budgeting-trial` includes accounting, expenses, capital, debt, business loans, lenders, transfers, and multi-account. `budgeting-basic` adds master data, account types, staff management, currencies/rates, and dashboard. `budgeting-premium` is currently inactive and has no feature array in the configuration.

**Availability note:** a service/controller existing in the backend does not by itself make a UI workflow reachable. The route gates above reflect the frontend configuration. In particular, Commerce gates are defined and active globally but omitted from the standard package arrays, while Delivery has no configured feature key. Confirm package assignment and the exact feature response when assessing a specific tenant.

## Main cross-module transaction chains

- **Pawn issue:** customer → collateral → loan contract/slip → interest schedule → financial account disbursement → accounting transaction → operation/audit log → slip document.
- **Pawn collect:** slip → interest or redemption calculation → accepted payment → financial account → accounting transaction → updated slip/collateral lifecycle → payment/audit history.
- **Purchase stock:** supplier → purchase order → receipt → inventory movement → supplier payable/payment → account and accounting entries.
- **Sale fulfilment:** sale order → inventory allocation/stock movement → payment or customer receivable → accounting entries → delivery → return/refund and inventory adjustment if needed.
- **Scheduled expense:** schedule and due occurrence → expense record → account payment → accounting entry → occurrence/payment result and logs.
- **Debt/loan servicing:** lender/customer/debt → receipt or payment → principal/interest allocation → financial account → accounting entry → updated payable/receivable and history.
- **Expired collateral acquisition:** expired slip/collateral → explicit ownership decision and cost → owned item/lot → inventory record and movement → audit/history.

## Orchestration functions and implementation steps

The sequences below name the service method that coordinates each write workflow. Numbered steps follow the service call order and include validation, transaction boundaries, linked records, and post-commit work where present. Read/list/detail endpoints are noted separately when they do not orchestrate a write. The steps describe implementation behavior, not only the visible screen action.

### Pawn operations

#### Create pawn slip — `LoanContractServices\ManagementService::create(LoanContractSlipCreate $request)`

1. Authorize loan-contract creation.
2. Apply tenant settings; if customer details are not required, replace the supplied customer with an auto-generated “Unknown Customer”.
3. Normalize and validate customer, collateral, interest, amount, and request fields.
4. Resolve the current tenant and business-clock timestamp; normalize expiry units, calculate expiry date, and validate the selected interest type against the duration.
5. Enforce the tenant's current-month slip quota.
6. Resolve and validate the active financial account.
7. Reserve the optional idempotency key using the normalized creation payload; return the prior result for a replay.
8. Resolve the creating tenant user, generate the slip number, and prepare collateral images before database persistence.
9. Begin a database transaction.
10. Create or reuse the tenant customer.
11. Create the active slip with customer, account, principal, interest terms, expiry, notes, and creator.
12. Create collateral rows and attach them to the slip.
13. Materialize the initial interest schedule row(s).
14. Increment the tenant's monthly slip usage.
15. Record the loan disbursement in accounting with currency and optional reporting exchange rate.
16. Record the corresponding financial-account transaction linked to the accounting transaction and slip.
17. Write the `loan_contract_slip.created` audit event with slip/customer/amount/collateral count.
18. Recalculate the customer's trust score and reload the slip detail before commit.
19. After commit, flush the slip-list cache and shape the response DTO.
20. Mark the idempotency record completed with the response. If the flow throws, clean prepared image files, mark the idempotency record failed, and rethrow; database changes roll back.

#### Change slip status — `LoanContractServices\ManagementService::changeStatus(PawnLoanContractSlip $slip, string $status)`

1. Begin a database transaction.
2. Update the slip status using a locked repository update.
3. Recalculate the trust score for the slip's customer.
4. Commit the transaction.
5. Flush the slip-list cache and return the updated slip.

#### Record interest payment — `InterestFlowService::payInterestBySlipNo(string $slipNo, InterestPaymentAccept $request)`

1. Reject a payment amount less than or equal to zero.
2. Resolve and validate the active receiving account.
3. Reserve the optional idempotency key from slip number and payment payload; replay a completed request when applicable.
4. Resolve the tenant business timestamp and payment date.
5. Begin a database transaction and lock the active slip.
6. Materialize any due interest rows that are missing before validating the submitted breakdown.
7. Compare the slip update key with the submitted key to prevent stale concurrent updates.
8. Lock unpaid interest rows through the current timestamp; reject if none exist.
9. Verify every selected row uses the receiving account's currency and that submitted row IDs/count/update keys still match the locked rows.
10. Calculate the remaining interest per row after paid and compounded amounts; require enough payment unless the request allows recording the remainder as debt.
11. Allocate the payment across unpaid rows in order, update paid state/account/date/amount/update key, and create a debt for any allowed unpaid remainder.
12. Apply any payment excess as change to the last processed row and annotate it.
13. For each paid row, create accounting and account transaction records and write the interest-payment update log.
14. If change is due, create the corresponding change accounting/account transaction.
15. Calculate the renewal window and update slip interest dates, expiry, and update key.
16. Create the next interest schedule row.
17. Recalculate the customer's trust score and commit.
18. Mark the idempotency request completed and return status, debt, change, and paid amounts. On error, mark the idempotency request failed and rethrow.

#### Redeem pawn slip — `PawnRedemptionService::createRedemption(PawnRedemptionCreate $request)`

1. Authorize the operation and resolve the active receiving account.
2. Reserve the optional idempotency key and replay a completed request if present.
3. Resolve creator and redemption timestamp.
4. Begin a database transaction; find and lock the slip by slip number.
5. Validate that the slip can be redeemed and that the receiving account currency matches.
6. Recalculate current redemption totals under row locks; reject a client total that differs from the server amount or a payment below the amount due.
7. Calculate gross, net, interest, received, and change amounts; create the redemption record.
8. Settle interest rows for redemption and process supplied debt settlements.
9. Change slip status to `redeemed` and run the status-change trust-score update.
10. Run collateral redemption processing to release/update collateral state.
11. Write the `pawn_redemption.created` audit event.
12. Record the redemption receipt in accounting and the financial-account ledger.
13. If change is positive, record the change accounting entry and matching account adjustment.
14. Commit, flush redemption list cache, shape the response, and mark idempotency completed; on failure mark it failed and rethrow.

#### Collateral registration/update — `CollateralItemService::createForSlip(...)` (called by slip creation) and `CollateralItemService::update(PawnCollateralItemUpdate $request)`

1. For slip creation, receive the slip and collateral payload from `ManagementService::create` inside the slip transaction.
2. Persist each collateral item with tenant/slip ownership and its material, item description, value/weight/status, and prepared image references.
3. Where inventory linkage is configured, register custody through `InventoryService::receivePawnCollateral` and attach the inventory item ID.
4. For updates, authorize and resolve the tenant collateral item, validate the requested fields and eligible lifecycle state, then persist changes and related image/inventory linkage.
5. For redemption/expiry, `redeemProcess`, `expireForSlip`, and the expiration workflows transition collateral state; expired-collateral listing/locking is used by ownership transfer.

#### Slip expiration — `ExpirationService::refreshExpiration(PawnLoanContractSlip $slip)` / `checkCurrentTenant()`

1. Resolve the tenant business date and eligible active slips with an expiry date.
2. Lock each candidate during the transaction and recheck status/date to avoid stale transitions.
3. Mark eligible slips expired and invoke collateral expiry handling.
4. Recalculate affected customer trust scores and return the number or refreshed slip result.

### Customer, staff, configuration, and reference data

#### Customer create — `TenantCustomerService::createForCurrentTenant(...)` → `createCustomer(...)`

1. Resolve current tenant and normalize customer identity fields.
2. Validate required fields and tenant-scoped duplicate/reuse conditions.
3. Create or reuse the tenant customer through the repository inside the relevant transaction.
4. Return the upsert result; callers such as slip creation continue with the returned customer ID.

#### Staff create/update — `TenantUserService::createForCurrentTenant(...)`, `update(...)`, `updatePermissions(...)`

1. Authorize the tenant-user action and enforce staff quota for creation.
2. Validate identity, role, and requested permission data; generate tenant username/credentials as required.
3. Persist the tenant user and associated role/permission record transactionally.
4. For permission changes, normalize and persist the permission set and account assignments.
5. Return a shaped tenant-user DTO; staff activity/notification services record relevant events.

#### Tenant settings — `TenantSettingsService::updateCurrentTenantSettings(...)`

1. Resolve current tenant and validate setting values.
2. Persist tenant settings through the repository/service boundary.
3. Refresh settings/bootstrap data consumed by tenant workflows.

#### Master data and catalog — `DefaultDataService`, `CatalogTaxonomyService::createCategory/updateCategory/createUnit/updateUnit`, `CatalogItemService` write methods

1. Authorize and validate tenant-owned type, category, unit, or catalog item payload.
2. Resolve tenant scope and enforce uniqueness/referential constraints.
3. Persist the record (and its associations) using repository operations.
4. Return the normalized resource used by collateral, expense, inventory, purchase, or sale forms.

### Accounting, money movement, and currencies

#### Create expense — `TenantExpenseService::createForCurrentTenant(...)` → `createExpense(...)`

1. Authorize expense creation and resolve tenant/user context.
2. Validate expense type, amount, currency, date, account assignment, and optional exchange rate.
3. Begin a database transaction and persist the expense.
4. Resolve the active assigned financial account and record the account transaction.
5. Record the outgoing accounting transaction against the expense reference.
6. Commit and return the expense detail; scheduled expense creation uses `createFromSchedule` and calls the same core flow with schedule-specific account-assignment behavior.

#### Update/delete expense — `TenantExpenseService::update(...)` / `delete(...)`

1. Authorize and resolve the tenant expense.
2. Validate the revised amount, currency, account, and date.
3. In a transaction, synchronize or remove the source-linked accounting/account transaction and persist the change or deletion.
4. Return updated detail or completion status.

#### Capital entry — `TenantCapitalService::createForCurrentTenant(...)`

1. Authorize and validate capital direction, amount, date, currency, account, and notes.
2. Begin a transaction and create the capital record.
3. Record the contribution or withdrawal in accounting and the financial-account transaction ledger.
4. Commit and return the capital detail.

#### Debt create/payment — `TenantDebtService::createExternalDebt(...)`, `createInternalDebt(...)`, `recordPayment(...)` with `DebtInterestFlowService::pay(...)`

1. Authorize the requested debt action and resolve tenant-owned debt/account/currency records.
2. Validate debt direction/type, source or counter-account, amount, date, interest terms, and optional reporting exchange rate.
3. For internal debt, verify both sides of the internal movement and record the linked in/out accounting semantics.
4. Persist debt and initial balance in a transaction; create required account and accounting references for funded debt.
5. For payment, lock the debt and relevant unpaid interest rows; calculate interest due and validate allocation/account currency.
6. Allocate payment across interest/principal according to configured order and compound schedule; persist payment and updated outstanding balances.
7. Record the account and accounting transactions against payment references and update the debt status/history.
8. Commit and return the updated debt/payment result.

#### Business loan create/payment — `TenantBusinessLoanService::create(...)` / `pay(...)`

1. Authorize and validate lender, principal, currency, dates, interest terms, and receipt account.
2. Create the loan record transactionally and record the received funds in accounting and account history.
3. For payment, resolve and lock the loan and payment account; calculate due principal/interest and validate submitted allocation.
4. Create the loan payment record, update outstanding balances, and record principal/interest accounting transactions and account entries.
5. Commit and return loan/payment detail; compound schedule changes use `updateCompoundSchedule(...)` after validation.

#### Accounting view/manual entry — `TenantAccountingService::overview()`, `buildAccountingLedger(...)`, `create(...)`

1. Authorize access and normalize date/search/pagination filters.
2. Resolve tenant accounting-day/business-clock boundaries.
3. Query tenant-scoped accounting records and linked references through repositories.
4. Aggregate incoming/outgoing balances and report ledger rows; apply reporting currency values where available.
5. For manual creation, validate category, amount, account, currency, date, and exchange rate, then persist the accounting record transactionally with the corresponding account movement.
6. Return the overview, paginated ledger, or streamed ledger export.

#### Account transfer — `FinancialAccountTransferService::create(...)`

1. Authorize transfer and resolve source and destination accounts within the current tenant.
2. Validate both accounts are active, user-assigned, currencies/rate are valid, amount is positive, and source balance supports the transfer and fee.
3. Reserve idempotency if supplied and lock accounts in the transaction.
4. Create the transfer reference and paired debit/credit account transactions.
5. Record internal-transfer accounting plus any fee transaction.
6. Commit, shape the transfer result, and complete the idempotency record.

#### Currency and exchange rates — `TenantCurrencyService::create/update(...)`, `TenantExchangeRatePairService::create/update(...)`, `TenantExchangeRateService::create(...)`

1. Authorize the relevant currency/pair/rate action and enforce package quota.
2. Validate currency code, precision, active/base/reporting settings, pair direction, effective date, and positive rate.
3. Enforce tenant-scoped uniqueness and prevent invalid changes to currencies already used by posted transactions.
4. Persist the currency/pair/rate and any default/reporting-currency preference updates.
5. For historical reporting rates, validate missing-rate requirements, queue/recalculate affected reporting values, and create a tenant notification when complete.

#### Accounting day — `TenantAccountingDayService` open/close operations

1. Authorize opening or closing and resolve tenant business date/timezone.
2. Validate current day state and any configured automatic schedule.
3. Persist the day transition and associated summary/snapshot values.
4. Return the resulting day state; scheduled weekly open/close follows the same service rules.

### Expense schedules, commerce, stock, and ownership

#### Scheduled expense configuration/payment — `TenantScheduledExpenseService::create/update(...)` and occurrence payment flow

1. Authorize and validate schedule amount, expense type, account, start/end dates, recurrence, and next occurrence.
2. Persist the schedule and initial occurrence state.
3. On due processing, lock each eligible occurrence and prevent duplicate payment.
4. Materialize the occurrence as an expense by calling `TenantExpenseService::createFromSchedule(...)`.
5. Record account and accounting movements through the expense flow.
6. Mark the occurrence paid or record its failure/retry state; pause/resume/delete operations update schedule state and history.

#### Inventory receipt/movement — `InventoryService::receive(...)`, `move(...)`, `issue(...)`, `adjust(...)`

1. Validate item, quantity, unit, source/destination locations, and tenant ownership.
2. Reserve/check idempotency to prevent duplicate stock actions.
3. Begin the write transaction and lock the affected inventory item/balance rows.
4. Enforce available quantity and valid custody/location transitions.
5. Persist stock balance and movement records with source reference and actor.
6. Commit, complete idempotency, and return updated inventory/movement details.

#### Purchase order — `PurchasingService::createOrder(...)`, `transition(...)`, `recordReceipt(...)`, `recordPayment(...)`, `recordReturn(...)`

1. Validate supplier, line items, catalog/inventory references, quantities, prices, currency, account, and requested action.
2. Enforce idempotency where supplied and resolve tenant-scoped order/supplier records.
3. For create/update, persist order and line rows; transitions enforce allowed purchase status changes.
4. For receipt, lock the order and lines, validate received quantities, create receipt rows, and receive stock through inventory.
5. For payment/refund, create payment records and update supplier payable balance; record account and accounting transactions.
6. For return, validate received-but-returnable quantities, persist return rows, reduce stock/owned quantity as applicable, and adjust supplier balance/accounting.
7. Commit and return refreshed order, payment, receipt, or return detail; list/detail services expose supplier payables.

#### Supplier — `PurchaseSupplierService::create(...)` / `update(...)`

1. Validate supplier identity/contact and tenant-level uniqueness.
2. Reserve idempotency for creation when supplied.
3. Persist supplier information and optional audit record.
4. Return the supplier resource used by purchase orders.

#### Sales order — `SalesService::createOrder(...)`, `transition(...)`, `recordPayment(...)`, `quickSale(...)`

1. Validate customer, item lines, quantities, price/discount, currency, account, and action.
2. Enforce idempotency and resolve tenant-scoped customer/catalog/inventory records.
3. Persist order and line rows (or build the combined order in `quickSale`).
4. On confirmation/fulfilment, reserve inventory or owned-item lots and enforce available quantities.
5. On payment, validate tender/account and record payment, account transaction, and accounting entry.
6. For credit sales, create/update the receivable balance and customer debt reference.
7. Apply allowed status transition, commit, and return refreshed order detail.

#### Sale return — `SaleReturnService::create(...)`

1. Validate order, returned lines/quantities, reason, refund method, and idempotency key.
2. Lock the sale order and verify returned quantity does not exceed sold, unreturned quantity.
3. Create the sale-return header and lines.
4. Restore inventory or owned-item quantities for accepted returned items.
5. Create refund/payment adjustment and linked accounting/account records where a refund is due.
6. Update order/receivable totals and return state; commit and complete idempotency.

#### Delivery — `SalesService::recordDelivery(...)` and delivery workflow

1. Resolve the sale order and validate delivery lines against outstanding fulfilment.
2. Validate destination/customer, quantities, delivery date, and status transition.
3. Create delivery record/lines and link them to the sale.
4. Issue or move reserved inventory according to delivery state.
5. Update delivered quantities/order state and return delivery details.
#### Read purchase/sale workflows

1. `PurchasingService::orders/order/payments/receipts/returns` and `SalesService::orders/order` resolve tenant-scoped records and shape their associated lines, statuses, balances, movements, and histories.
2. `SaleReturnService::returns(...)` lists return history for a tenant sale.
3. `SupplierPayableService::list(...)` and `SaleReceivableService::list/detail(...)` expose open supplier/customer balances. These reads do not create receipt stock, Ownership, or financial postings.

#### Ownership acquisition/expired collateral — `OwnershipService::manualAcquisition(...)` / `transferExpiredCollateral(...)`

1. Validate acquisition item, quantity, cost, currency, date, lot data, and idempotency key.
2. For expired collateral, lock the source collateral and confirm it belongs to an expired, transferable slip and has not already transferred.
3. Calculate/default the acquisition cost using the applicable collateral cost rule.
4. Create owned item and acquisition lot with source reference and cost basis.
5. Create or link the corresponding inventory item and stock movement.
6. Mark the source collateral ownership as transferred and persist the relationship.
7. Commit, complete idempotency, and return ownership, lot, and inventory details.

### Dashboard, documents, authentication, and platform operations

#### Dashboard — `TenantDashboardService` summary methods

1. Authorize dashboard access and resolve tenant/date-range/business timezone.
2. Read aggregated tenant-scoped pawn, accounting, expense, account, debt, and commerce data through repositories/services.
3. Convert values to configured financial units/reporting currency where applicable.
4. Return summary cards and detail series; dashboard reads do not create business records.

#### Slip document — `LoanContractSlipDocumentService::previewHtml(...)` / `downloadPdf(...)`

1. Authorize document preview/download and validate slip/document request.
2. Resolve the tenant slip, tenant branding, paper settings, and active layout configuration.
3. Build the render context from customer, contract, collateral, and tenant details (or a dummy context for layout preview).
4. Normalize/render header and footer components and barcode data.
5. Return HTML preview or render PDF and return the file response; no loan/accounting state is changed.

#### Tenant authentication and license gates — `AuthService`, `TenantSsoService`, `TenantLicenseService`

1. Resolve tenant from request context/subdomain and reject invalid tenant context.
2. Validate credentials or SSO token against tenant-user authentication context.
3. Enforce login lockout/session rules and build permission/feature/license state.
4. Record authenticated activity and return tenant session data.
5. On protected requests, verify license status, package feature, and user permission before the route/controller proceeds.

#### Platform tenant provisioning/licensing — `AdminTenantProvisioningService`, `TenantLicenseService` (PlatformModule), `TenantManagementService`

1. Authenticate and authorize platform administrator action.
2. Validate tenant identity, owner contact, selected plan/package, and requested subdomain/settings.
3. Create tenant and provision initial owner, default tenant settings/data, and license/package state transactionally.
4. Apply quotas and package feature assignments; validate slug/subdomain uniqueness.
5. Return tenant detail and access information; later package/license transitions update entitlement state used by tenant gates.

#### Platform billing/package management — `AdminBillingService`, `PackageService`, `PlatformBillingService`

1. Authorize platform billing or package-management permission.
2. Validate plan/package, pricing, feature flags, quota, billing period, and payment request details.
3. Persist plan/package/flag changes or billing/payment-request state.
4. Apply entitlement/license changes to the tenant and preserve billing history.
5. Trigger platform notification/support follow-up where configured and return the updated resource.

#### Platform users, support, dashboard, and reference data

1. Authenticate platform user in the platform guard and authorize the specific administration action.
2. Validate platform-user/support/global-reference payload independently of tenant-user permission context.
3. Persist platform user, support ticket, global currency/rate/catalog-unit, or platform setting through the corresponding PlatformModule service.
4. For tenant-linked support/billing actions, resolve tenant through platform tenant services and retain platform/tenant ownership separation.
5. Return platform resource or dashboard aggregation; Telegram/webhook services notify only configured platform events.

### Read workflows and audit coverage

1. List/detail/history methods resolve tenant context and pass tenant-scoped filters to repositories; they shape response DTOs and do not mutate business state unless they explicitly materialize due rows (interest calculation/payment and expiration refresh can do so).
2. Write methods may combine a transactional business record with account ledger rows, accounting records, inventory movements, schedule occurrences, or audit events as listed above.
3. `TenantAuditLogService`, `OperationLogger`, and service-specific logging are used across material operations, but audit coverage is not uniform on every status/cache/settings update. The flow descriptions name explicit audit writes where confirmed in the orchestration method.
4. Idempotency is used on workflows that accept an idempotency key; the numbered process identifies those flows. It should not be inferred for actions whose method signature does not accept one.

