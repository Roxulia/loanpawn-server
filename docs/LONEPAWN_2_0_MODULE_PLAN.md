# LonePawn 2.0 — Detailed Module Development Plan

## 1. Purpose and agreed release boundary

LonePawn 2.0 introduces fully paid Quick Buy and Quick Sell, whole-pack transactions, and Business Loan collateral. Catalog, Inventory, Ownership, and Pawn integrations provide their required foundations.

This document defines the 2.0 release scope. `ASSET_INVENTORY_DEVELOPMENT_PLAN.md` remains the long-term roadmap; its unfinished features are not automatically requirements for this release.

**Accounting, Financial Account operations, logging, auditing, idempotency, notifications, and cache updates belong inside the module that calls them. They are not separate modules to develop.** Each caller's workflow and acceptance checklist must cover its related effects using the existing services.

This is a development plan only. Creating this document does not authorize code implementation, migration execution, database changes, or a development server.

### 1.1 Deployment assumptions

- Fresh deployment: no existing business records or test data require migration/backfill.
- Preserve existing Pawn, finance, and Business Loan behavior except changes required by the specified integrations.
- Use additive schema changes and preserve compatible public contracts where practical.
- Use current Basic/Premium entitlement defaults for included asset capabilities. Deferred capabilities remain globally inactive until reviewed for a future release.
- Existing Pawn jewellery-pack composition records remain separate from generic Inventory packs.

### 1.2 Scope matrix

| Module | Included in 2.0 | Deferred |
|---|---|---|
| Inventory | Quantity/unique/serialized tracking; locations; moves; stock, serialized-item, and movement views; internal business receipt/issue; scheduled monthly quantity checkpoints | Standalone receipt, issue, adjustment; full stock reconciliation; continuously updated balance table |
| Ownership | Owned items, lots, costs, history; purchase acquisition; sale reservations/reduction; expired-Pawn acquisition; collateral quantity operations | Standalone manual acquisition |
| Catalog | Search, quick-create, optional references, required category/unit configuration | Expanded administration, variants, reusable commercial bundles |
| Purchasing | Supplier selection/creation; fully paid Quick Buy; immediate full receipt; history | Staged orders, partial receipts, advances, unpaid purchases/payables, returns/refunds |
| Sales | Fully paid Quick Sell; tender/change; immediate fulfilment; item and whole-pack sales; costs/accounting/history | Delivery/deferred orders, credit sales/receivables, returns/refunds |
| Pawn integration | Custody receipt/release; explicit expired-collateral acquisition; deletion/custody consistency | Broader Pawn redesign and historical custody backfill |
| Packing | Pack/unpack; whole-pack move, sale, pledge; composition/lifecycle history | Nested packs, reusable bundles, partial pack transactions |
| Business Loan collateral | Pledge, lender custody, release, confirmed forfeiture and non-cash settlement | Automatic forfeiture, substitution, advanced valuation |

Operational histories are included in their owning modules. Full Stock Reconciliation and advanced reports remain deferred.

### 1.3 Development order

1. Review and stabilize Inventory.
2. Review and stabilize Ownership.
3. Review Catalog and its consuming selectors.
4. Complete the scoped Quick Buy workflow.
5. Complete the scoped loose-item Quick Sell workflow.
6. Complete Pawn custody and expired-acquisition integration.
7. Implement Packing and connect whole-pack Sales.
8. Implement Business Loan collateral, including whole packs.
9. Verify all included end-to-end workflows and release gates together.

Each module review produces concrete findings with examples and code references, followed by fixes and acceptance verification. Fix correctness and concurrency defects required by this release; record unrelated improvements against deferred features. Query optimization must be supported by inspection or measurement.

## 2. Shared implementation rules

These rules apply to every module part; they are not a separate implementation module.

### 2.1 Architecture and references

1. Preserve controller → service → repository layering.
2. Keep orchestration/business rules inside services and queries inside repositories.
3. Use typed request/response contracts and existing project exception/message conventions.
4. Validate tenant ownership of every referenced item, unit, lot, location, account, supplier, customer, pack, lender, and loan.
5. Use public business codes where established; resolve internal keys inside the appropriate service/repository boundary.
6. Keep Pawn, Purchasing, Sales, and Business Loan relationships in their respective modules. Do not add business-specific relationship columns to generic Inventory records.
7. Reuse existing Accounting and Financial Account services rather than creating parallel posting mechanisms.
8. Guard expected duplicate and invalid-reference conditions before writes, using existing exception classes with specific localized messages. Retain database uniqueness constraints for concurrent races; do not use SQL-error translation as routine business validation.
9. Replace generic missing-resource HTTP aborts with named exceptions/messages at the service boundary, reusing existing exception classes where applicable.

### 2.2 Transactions, locks, and retries

1. Let the caller own the transaction encompassing its business records and all database-local effects.
2. Establish and document one consistent lock ordering across Inventory, Ownership, packs, and collateral before modifying their shared operations.
3. Recheck quantities, states, and identities after acquiring locks; UI values and pre-lock queries are not authoritative.
4. Protect reservations and quantity reductions against concurrency.
5. Use stable source references and idempotency keys on stock-changing business actions.
6. Persist idempotency completion with committed results; replay must not duplicate stock, lots, postings, account movements, or audit events.
7. Roll back all related records when a required step fails.
8. Retain historical movements; corrections use additional explicit operations.

### 2.3 Caller-owned financial and related operations

1. Identify the canonical business operation and link every stock, Ownership, accounting, and account record to it.
2. Resolve the account, currency, business timestamp, and acting user through existing tenant-aware services.
3. Validate accounting-day eligibility through existing financial rules.
4. Prevent nested service calls from recognizing the same cash, asset, revenue, or liability twice.
5. Write the caller's material audit event inside the successful business transaction, including linked record references.
6. Avoid duplicate nested operation audits when a composite action already has a completion event.
7. Preserve failure diagnostics through existing operation logging without leaving a successful audit for rolled-back work.
8. Invalidate affected caches and dispatch applicable notifications after successful commit.
9. Do not introduce a new notification or logging subsystem for this release.

### 2.4 Feature gates and permissions

| Capability | Gate | Release state |
|---|---|---|
| Catalog basics | `catalog_management` | Included |
| Inventory basics | `inventory_management` | Included |
| Ownership basics | `ownership_management` | Included |
| Quick Buy | `purchasing_management` | Included |
| Quick Sell | `sales_management` | Included |
| Generic Packing | `inventory_packing_management` | Add; included |
| Business Loan collateral | `business_loan_collateral_management` | Add; included |
| Staged purchasing | `purchase_order_management` | Add; inactive |
| Delivery/deferred sales | `delivery_management` | Inactive for this release |
| Purchase credit/payables | `purchase_credit_management` | Add; inactive |
| Sale credit/receivables | `sale_credit_management` | Add; inactive |
| Purchase returns | `purchase_return_management` | Add; inactive |
| Sale returns | `sale_return_management` | Add; inactive |
| Standalone Inventory writes | `inventory_manual_operations` | Add; inactive |
| Manual Ownership acquisition | `ownership_manual_acquisition` | Add; inactive |
| Full reconciliation | `stock_reconciliation` | Inactive; functionality deferred |

1. Enforce gates in backend endpoints and frontend routes, navigation, and actions.
2. Keep global readiness (`is_active`), package assignment (`is_enabled`), and staff permission checks distinct.
3. Require Inventory and Ownership features for included workflows that depend on them. Business Loan collateral additionally requires existing Business Loan access.
4. Internal receipts/issues/acquisitions are authorized by the owning Quick Buy, Quick Sell, Pawn, pack, or collateral action. They do not require gates/permissions for disabled standalone stock operations.
5. Do not require account-management access merely to perform an otherwise authorized payment; preserve existing account-assignment checks.
6. Register dedicated pack/unpack, collateral-management, and forfeiture permissions through the complete backend/frontend permission path.
7. Align migrations, configuration, default package mappings, and seeding. Do not reset platform-admin choices when reseeding.
8. Adding a gate must not duplicate the underlying business implementation; shared operations remain reusable behind their public entry points.

## Part 1 — Inventory

### Included capabilities and boundaries

- Track QUANTITY, UNIQUE, and SERIALIZED stock.
- Manage locations and move available stock.
- Show physical on-hand, operational availability, unit identity, location, and immutable movement history.
- Provide internal receive/issue/reservation operations to reviewed callers.
- Store monthly closing quantities by tenant/item/location and calculate current quantities from the latest completed checkpoint plus uncovered movements.
- Run a monthly scheduled Inventory summarizer using the financial summarizer's queue, operation-logging, and overlap conventions.
- Gate standalone receipt, issue, and adjustment with `inventory_manual_operations`.
- Store no purchase prices, profit, revenue, interest, liability, or expense rules in Inventory.

### Monthly quantity checkpoint scope amendment

Monthly physical quantity checkpoints are included in Part 1, superseding the earlier exclusion of all stored balance projections. Full movement history remains canonical and is retained. This is an Inventory responsibility, not an Accounting or financial module.

1. Close each tenant-local month using its configured timezone and a stored UTC cutoff.
2. Store exact closing quantities per item/location and a movement-ID watermark under an item anchor lock.
3. Calculate new-month quantities as the latest completed opening base plus uncovered incoming/outgoing movement deltas.
4. Require both recording time and ID watermark coverage before excluding a movement from live calculations; backdated business dates remain safe.
5. Keep active reservations and serialized-item custody live.
6. Fall back to an older checkpoint or the full ledger if the previous month's checkpoint is unavailable.
7. Publish each item atomically, retain movement history, and make repeated jobs idempotent.
8. Schedule `SummarizeMonthlyInventoryMovementsJob` monthly on day 2 at 00:45 UTC, alongside the financial job; dispatch independent tenant jobs.
9. Provide `inventory:summarize-monthly` for explicit recovery of missed checkpoints.
10. Verify source-ledger equivalence, concurrency, time boundaries, retries, and MySQL query plans before declaring production readiness.

Detailed schema, orchestration, dependency checklist, integration boundaries, and pending verification are in `INVENTORY_MONTHLY_SUMMARIZER_PLAN.md`. No continuously updated balance table or movement deletion is included.

### Classes and integration areas

Review `InventoryService`, `InventoryRepository`, Inventory models/migrations, `InventoryController`, API gates, frontend Inventory screens/services, and their business callers.

### Detailed operation sequence

1. Resolve tenant context, acting user, source operation, and optional idempotency key.
2. Resolve item, exact units, source/destination location, and applicable reservation/pack references.
3. Validate tracking-mode quantity: UNIQUE quantity one; SERIALIZED whole quantities with matching distinct unit identities; QUANTITY without per-unit identity requirements.
4. Validate same-tenant references, location state, and operation-specific restrictions.
5. Acquire locks in the documented order and recheck current stock/unit custody.
6. Calculate availability after active reservations, pledges/custody restrictions, and pack membership.
7. Reject insufficient quantity, reused/issued serialized units, invalid locations, or negative resulting balances.
8. Append the receipt, move, issue, or reservation effect with canonical source, actor, timestamp, and idempotency linkage.
9. Preserve previous movements. Moving stock must conserve total physical quantity.
10. Return updated item/unit/location state to the caller.
11. For public location/move actions, record the Inventory audit and complete idempotency in the same transaction.
12. Refresh affected caches after commit. For internal operations, let the business caller record the primary completion audit and financial effects.

### Sale availability contract

1. A sellable item must already exist physically in Inventory and be linked to tenant Ownership.
2. Catalog-only or Ownership-only records cannot authorize a sale.
3. Require enough physical quantity at active, sellable locations and enough available owned quantity in eligible lots.
4. Exclude issued, reserved, pledged, quarantined, and otherwise unavailable stock.
5. Exclude packed components from loose-item actions; make them available only through the validated whole-pack operation.
6. For serialized goods, verify exact units remain at the selected location immediately before issue.
7. Do not create Inventory automatically or allow negative stock to satisfy a sale.

### Review checklist

- Verify receive/move/issue use compatible lock ordering.
- Verify reservation creation and consumption recheck availability under locks.
- Verify serial identity selection is not reused across allocations.
- Verify non-sellable locations cannot feed sale reservations/issues.
- Review stock queries and history loading for measurable repeated-query problems.

### Acceptance scenarios

1. Receive, move, and issue reconcile for all three tracking modes.
2. Moves conserve quantity and preserve serialized identity.
3. Wrong-location, duplicate-unit, and insufficient-stock requests fail without side effects.
4. Concurrent operations cannot consume the same quantity/unit.
5. Replay creates no additional movement.
6. Disabled standalone write endpoints are inaccessible.
7. Monthly opening quantities plus live deltas agree exactly with full movement history, including delayed jobs, backdated business dates, and current-month movement IDs below the summary watermark.
8. Concurrent summary/write operations and repeated jobs neither omit nor duplicate physical quantities.

## Part 2 — Ownership

### Included capabilities and boundaries

Owned items, acquisition lots, cost/history views, purchase acquisition, sale reservation/reduction, expired-Pawn acquisition, and collateral quantity primitives. Standalone manual acquisition is gated by `ownership_manual_acquisition`.

Ownership records entitlement and cost history. Its business caller owns financial recognition and the primary business audit.

### Classes and integration areas

Review `OwnershipService`, `OwnershipRepository`, owned-item/lot/movement models, `OwnershipController`, frontend Ownership views, and calls from Purchasing, Sales, Pawn conversion, and Business Loan collateral.

### Detailed operation sequence

1. Resolve tenant-scoped Inventory identity and source operation through their owning services.
2. Validate quantity, active currency, source type/code, and source eligibility.
3. For acquisition, prevent repeated source acquisition and preserve source, date, acquired quantity, cost basis, estimated value, and currency per lot.
4. Lock aggregate/lot records before calculating or changing entitlement.
5. Calculate owned, reserved, pledged, and available quantities from the authoritative Ownership ledger.
6. Validate every reservation/reduction against the correct balance. Positive sale reservations must not exceed availability.
7. For sale/return/pledge/release/forfeiture, target the exact affected lot and source operation.
8. Apply owned/reserved/pledged deltas consistently; forfeiture of pledged stock must validate pledged quantity rather than incorrectly requiring unpledged availability.
9. Preserve acquisition and movement history; release restores availability without another acquisition.
10. Return linked lot/movement references for caller accounting, account linkage, and audit.
11. Commit with the caller's business transaction and idempotency result.

### Acceptance scenarios

1. Multiple lots preserve independent quantities, costs, and currencies.
2. UNIQUE acquisition cannot duplicate current ownership.
3. Reservations cannot exceed available entitlement.
4. Pledged/reserved stock cannot be sold through another operation.
5. Fully pledged stock can be validly forfeited.
6. Release changes availability without creating lots or cash effects.
7. Retries do not duplicate acquisition or quantity movements.

## Part 3 — Catalog

### Included capabilities and boundaries

Search, quick-create, optional references, and category/unit configuration required by included workflows. Expanded administration, variants, and reusable commercial bundles remain deferred.

### Classes and integration areas

Review Catalog item/taxonomy services and repositories, request/response contracts, controller/routes, and the reusable Catalog selector.

### Detailed operation sequence

1. Resolve tenant context and validate requested item/category/unit values.
2. Permit optional SKU/barcode and one-off goods without Catalog linkage.
3. Do not identify or deduplicate Catalog items solely by name.
4. Resolve active same-tenant category/unit references and validate tracking mode.
5. Persist typed creation/update data and enforce existing concurrency tokens for updates.
6. Return category/description context sufficient to distinguish similar names.
7. Record material Catalog changes in its audit history.
8. Refresh affected search/options data after commit.
9. Create no Inventory, Ownership, accounting, or cash records from Catalog changes.

### Acceptance scenarios

1. Similar-name items remain distinguishable and selectable.
2. Unique goods work without Catalog linkage or scan identifiers.
3. Inactive and cross-tenant references are rejected.
4. Quick-create works in its consuming workflow.
5. Catalog edits do not alter historical purchase/sale snapshots.

## Part 4 — Purchasing / Quick Buy

### Included capabilities and boundaries

Supplier selection/inline creation, fully paid Quick Buy, immediate full receipt, and purchase/receipt/payment history.

Staged orders/partial receipts, supplier advances, unpaid purchases/payables, and returns/refunds remain behind their deferred gates. Existing internal draft/transition helpers may be reused without exposing their public workflows.

### Main caller and related services

`PurchasingService::quickPurchase(...)` owns the composite workflow. Review its calls to supplier creation, order creation/transitions, payment, Inventory receipt, Ownership acquisition, accounting/account services, and audit/idempotency handling.

### Detailed operation sequence

1. Validate supplier or inline supplier data, purchase currency/date, items, quantities, identifiers, location, and payment.
2. Require payment applied to equal the purchase total; explicitly reject unsupported unpaid purchases.
3. For positive payment, resolve an active assigned account matching the purchase currency. Zero-total purchases create no debt.
4. Reserve idempotency, resolve the actor/business timestamp, and begin the caller transaction.
5. Create an inline supplier where authorized, then purchase and item snapshots through shared internal operations.
6. Receive all purchased quantities immediately into the selected location through Inventory.
7. Preserve serialized identities, reuse appropriate Inventory identities for tracked goods, and retain stable receipt-line source references.
8. Acquire receipt-sourced Ownership lots using snapshotted purchase cost and currency.
9. Record the required stock Asset recognition and full-payment accounting through existing services, without leaving a supplier payable or advance balance.
10. Record the matching account cash outflow and link account, accounting, payment, receipt, item, and lot records.
11. Prevent duplicate financial recognition from nested internal order/payment/receipt operations.
12. Write one coherent Quick Buy completion audit with source and financial references; suppress redundant nested completion audits.
13. Commit all effects, complete idempotency, and refresh affected purchase, stock, Ownership, and account views/caches.
14. On failure, roll back all database-local effects and retain existing failure diagnostics.

### Acceptance scenarios

1. Inventory and Ownership both increase by the purchased quantity.
2. Positive transactions are fully paid; zero-total transactions create no credit balance.
3. Stock value, purchase cost, payment, account, and accounting records reconcile.
4. Serialization, ownership, account, or posting failure rolls back the whole purchase.
5. Retry creates one purchase and one set of effects.
6. Public staged/credit/return actions remain inaccessible while gated.

## Part 5 — Sales / Quick Sell

### Included capabilities and boundaries

Immediate fully paid loose-item and whole-pack sales, tender/change, cost snapshots, and sale history. Delivery/deferred orders, credit sales/receivables, and returns/refunds remain gated.

### Main caller and related services

`SalesService::quickSale(...)` owns completion, using shared creation/reservation/immediate fulfilment operations, Inventory, Ownership, pack lifecycle, and existing financial/audit services.

### Detailed operation sequence

1. Validate optional customer, currency/date, loose-item or whole-pack selections, quantities, and tender.
2. Resolve existing physically present Inventory linked to tenant Ownership. Do not satisfy a sale with Catalog or Ownership records alone.
3. Select eligible acquisition lots in the sale currency and available stock at active, sellable locations.
4. Exclude reserved, pledged, quarantined, restricted, and packed-as-loose quantities.
5. For serialized goods, select distinct exact units and update selection across lot/location allocations so a unit is not reused.
6. Reserve idempotency and acquire the required locks; recheck both physical and owned availability.
7. Require full payment. Calculate excess tender as change. Missing payment permission must reject the operation instead of creating a receivable.
8. Snapshot cost and component proceeds. Whole-pack price allocation follows Part 7.
9. Issue Inventory and reduce the corresponding Ownership lots in the same transaction.
10. Record sale Revenue, COGS, and stock Asset relief through existing accounting services.
11. Record the payment/account receipt and separate outgoing change effect with canonical business references.
12. Reuse existing internal deposit helpers only if completion clears their temporary balance; the completed quick sale must leave no unpaid receivable or unused deposit.
13. Update pack lifecycle when applicable and write the completed-sale audit with stock, lot, cost, payment, change, and posting references.
14. Commit, complete idempotency, and refresh related views/caches.
15. On failure, roll back sale, stock, Ownership, pack, payment, accounting, and audit effects together.

Cross-currency costing remains deferred.

### Acceptance scenarios

1. Missing, zero, insufficient, non-sellable, or wrong-currency stock blocks completion.
2. Concurrent sales cannot oversell the same item/unit/lot/pack.
3. Serialized multi-lot allocation preserves distinct units.
4. Costs, proceeds, Revenue, stock Asset relief, payment, and change reconcile.
5. Completed sales leave no unpaid balance.
6. Financial or stock failure leaves no partial sale.

## Part 6 — Pawn Creation, Redemption, and Expired Acquisition

### Included capabilities and boundaries

Custody receipt on creation, custody release on redemption, explicit expired-collateral acquisition, and deletion/custody consistency. Preserve existing jewellery-pack composition and core Pawn calculations. Historical backfill is not a fresh-deployment release requirement.

### Slip creation — caller: `LoanContractServices\\ManagementService::create(...)`

1. Validate and create the contract through existing Pawn orchestration, including tenant quota, account, customer, collateral, and expiry rules.
2. Receive collateral into Inventory custody and save its link.
3. Keep customer property outside tenant Ownership.
4. Create the interest schedule and existing disbursement/accounting effects.
5. Record account movement and the Pawn creation audit with its linked Inventory references.
6. Commit and complete idempotency; invalidate affected caches after success.

### Redemption — caller: `PawnRedemptionService::createRedemption(...)`

1. Lock and validate slip, collateral, current payable amounts, and receiving account.
2. Process existing principal, interest, and debt settlement requirements.
3. Issue the linked physical custody quantity exactly once through Inventory.
4. Update Pawn lifecycle and preserve trust-score behavior.
5. Record receipt/change accounting and corresponding account movements.
6. Write redemption audit with custody, payment, and posting references.
7. Commit all effects together, complete idempotency, and refresh related caches.

### Expired acquisition — caller: `OwnershipService::transferExpiredCollateral(...)`

1. Validate an eligible expired slip/collateral and reject a repeated transfer.
2. Resolve and lock the existing Inventory identity; expiry alone must not grant Ownership.
3. Record explicit acquisition, quantity, source, cost basis, estimated value, and valid currency.
4. Preserve the existing collateral-cost prefill rule and acquisition history.
5. Apply any required non-cash recognition through this caller, without creating a second cash receipt or duplicating stock value.
6. Mark the source transferred and write its acquisition audit.
7. Commit, complete idempotency, and make the acquired goods available to Quick Sell and Packing.

### Deletion and history

1. Review existing slip/collateral deletion paths against posted custody and financial records.
2. Reject deletion that would leave outstanding custody or erase linked posted history.
3. Preserve traceability of business effects and source identities.

### Acceptance scenarios

1. Creation receives custody but not Ownership.
2. Redemption removes custody and settles existing Pawn financial requirements exactly once.
3. Expired acquisition reuses Inventory identity and creates no duplicate stock/cash.
4. Acquired expired collateral can subsequently be packed or sold.
5. Retry and failure handling preserve quantity, financial, and audit consistency.

## Part 7 — Packing and Unpacking

### Included capabilities and boundaries

Tenant-owned QUANTITY, UNIQUE, and SERIALIZED components; pack/unpack; whole-pack move, sale, and pledge. Deferred: nested packs, reusable commercial bundles, and partial pack transactions.

### Data and interfaces

- Add Inventory-owned pack and component records with lifecycle/history.
- Preserve component item/unit, quantity, acquisition-lot, and location references.
- Expose pack/unpack/move actions and contextual Inventory/Ownership controls.
- Extend Quick Sell and Business Loan collateral selection to accept whole packs.
- Keep generic pack identity separate from Pawn jewellery composition.

### Pack/unpack operation sequence

1. Validate available tenant-owned components at one location and in one acquisition currency.
2. Require explicit Ownership acquisition before packing expired Pawn collateral.
3. Reject pledged, reserved, already packed, or otherwise restricted quantities.
4. Reserve idempotency, lock components in deterministic order, and recheck their state.
5. Create or remove grouping relationships while preserving exact identities, quantities, lot references, and costs.
6. Create no additional physical stock or Ownership merely because a pack exists.
7. Block individual component sale, movement, pledge, or issue while contained.
8. Record pack/unpack audit and composition/lifecycle history.
9. Commit atomically, complete idempotency, and refresh availability/history views.

### Whole-pack movement and business transactions

1. Move every component together and conserve quantity/identity; record pack and movement audit references.
2. Quick Sell owns whole-pack sale payment, accounting, component proceeds, audit, and completion. Packing supplies validation/composition/lifecycle operations without duplicating those postings.
3. Business Loan collateral owns whole-pack pledge/release/forfeiture and its settlement effects.
4. Whole-pack operations are atomic; unpack before transacting only a subset of components.
5. Require release before unpacking a pledged pack.
6. Retain sold/forfeited pack history and reject unpacking disposed packs.

### One pack selling price

1. Accept one selling price for the whole pack.
2. Allocate proceeds proportionally to component acquisition cost multiplied by component quantity.
3. If all component costs are zero, allocate equally across component lines.
4. Allocate rounding remainder deterministically by component code.
5. Persist authoritative component monetary totals so their sum equals the entered pack price, independent of per-unit display rounding.
6. Preserve allocation snapshots for Sales history and margin calculations.

### Acceptance scenarios

1. Pack/unpack conserves quantity and exact serialized identities.
2. Pack and components are not double-counted as stock.
3. Components cannot be transacted individually or packed twice.
4. Whole-pack movement/sale/pledge preserves traceability and rolls back together on failure.
5. Price allocation totals exactly match pack price, including zero-cost components.
6. Packing itself creates no cash, Revenue, or extra stock Asset recognition.

## Part 8 — Business Loan Collateral

### Included capabilities and boundaries

Loose/whole-pack pledge, lender custody, release, confirmed forfeiture, and non-cash settlement. Deferred: automatic forfeiture, collateral substitution, and advanced valuation.

### Data and interfaces

- Add Business Loan-owned collateral records and quantity/state history.
- Add maturity data required for secured loans.
- Add distinct forfeiture settlement records and snapshots.
- Add collateral controls to Business Loan details and dedicated collateral/forfeiture permissions.
- Keep lender payments and existing loan principal/interest operations within the existing Business Loan workflow.

### Pledge sequence

1. Validate tenant loan, lender, maturity date, item/lot or pack, physical availability, and owned availability.
2. Resolve selected lender custody location and pledged value independently of cost basis.
3. Reserve idempotency and lock loan, Ownership, Inventory, and pack components consistently.
4. Reserve Ownership and move physical custody to the lender location.
5. Create collateral records and preserve source lot/unit/pack references.
6. Write collateral audit and commit all effects atomically.
7. Complete idempotency and refresh loan, stock, Ownership, and pack views.
8. Create no Sale or additional loan cash receipt merely from pledging collateral.

### Release sequence

1. Validate active pledge and requested release quantity/state.
2. Lock source collateral, lots, custody, and pack components.
3. Return custody to a selected tenant location and release the corresponding Ownership reservation.
4. Preserve acquisition lots, costs, and pack composition.
5. Support partial quantities for loose goods; release whole packs intact.
6. Write release audit, commit atomically, and complete idempotency.
7. Keep release explicit even when the loan has already been repaid.

### Confirmed forfeiture sequence

1. Require a maturity date; mark overdue loans without automatically surrendering collateral.
2. Require authorized confirmation after maturity.
3. Lock the loan and all remaining active collateral/lots/units/packs.
4. Snapshot remaining principal, unpaid interest, collateral quantity/cost, and linked operation references.
5. Forfeit all remaining pledged collateral and remove its physical custody and tenant Ownership.
6. Close remaining principal through a distinct non-cash settlement, not a cash repayment.
7. Cancel unpaid interest and stop future accrual. Retain cancelled accrual rows and paid-interest history.
8. Record discharged loan liability and forfeited stock Asset removal through existing accounting services; record their difference as gain/loss.
9. Do not create a Sale, capital cash contribution, or new Financial Account receipt. Cash received when the loan originated remains unchanged.
10. Preserve settlement snapshots and update affected pack/collateral lifecycle states.
11. Write the forfeiture audit with principal, cancelled interest, stock cost, and accounting references.
12. Commit all effects together, complete idempotency, and refresh loan/stock/Ownership views.

The existing Business Loan caller continues to own loan receipts, principal/interest cash repayments, account movements, accounting, and audit history. Collateral actions must not duplicate those existing postings.

### Acceptance scenarios

1. Partial loose pledge/release preserves remaining owned and pledged quantities.
2. Whole packs remain indivisible and their components cannot be sold or repacked while pledged.
3. Fully pledged stock can be forfeited correctly.
4. Maturity alone changes no custody/Ownership/cash balance.
5. Confirmed forfeiture closes principal, cancels unpaid interest, stops accrual, and retains history.
6. Forfeiture changes no cash balance and creates no Sale.
7. Payment, release, and forfeiture concurrency cannot settle or dispose the same obligation/collateral twice.
8. Failure and retry preserve stock, Ownership, financial, and audit consistency.

## 3. Release-wide acceptance and completion

These checks verify the module work above; they do not introduce separate Accounting, Financial Operations, Logging, or Reporting development modules.

1. Verify the complete Quick Buy → Inventory/Ownership → Quick Sell chain.
2. Verify Pawn creation → custody → redemption and expired acquisition → pack/sale chains.
3. Verify pack/unpack, whole-pack move/sale, and loose/whole-pack collateral lifecycle chains.
4. Check required financial/account effects and audit/source linkage within each caller's acceptance tests.
5. Check tenant isolation, staff permissions, feature dependencies, and direct API restrictions.
6. Check concurrent quantity/state changes, idempotency replay, and intermediate-failure rollback.
7. Check monetary rounding, pack-price allocation, serialized identity, and quantity conservation.
8. Check disabled credit, returns, standalone stock writes, manual acquisition, staged orders, Delivery, and reconciliation capabilities produce no side effects.
9. Check defaults, migrations, and seeding preserve the intended gate state and administrator overrides.
10. Include operational stock, lot, purchase, sale, pack, collateral, and settlement histories in their owning modules.
11. Keep full Stock Reconciliation and advanced reports deferred.

LonePawn 2.0 is complete when every included module and the connected end-to-end workflows pass their acceptance checks. Completing the entire long-term roadmap is not a release requirement.
