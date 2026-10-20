# LonePawn 2.0 — Part II Ownership Implementation Checklist

## Purpose

Track Part 2 Ownership as small, tests-first, dependency-first review units. This checklist follows the format of [the Part 1 Inventory checklist](INVENTORY_PART_1_IMPLEMENTATION_CHECKLIST.md) and supplements [the main module plan](LONEPAWN_2_0_MODULE_PLAN.md).

The required sequence is **requirement analysis → test writing → feature gates → helper function writing → orchestration function writing → confirmations**. Controllers, API routes, frontend connections, caller adaptations, and monthly summaries fit inside that sequence; they are not permission to skip dependencies.

All checkboxes start unchecked. Existing code is not complete until reviewed against these requirements. Creating this document does not implement features, write executable tests, run tests, approve migrations, or establish verification results.

Paths are relative to `lonepawn-server` unless prefixed with `lonepawn-frontend/`.

## Working rules

- **One child review unit per turn unless the user explicitly authorizes a batch.** A child ID such as OWN-06b identifies the review unit; the whole OWN-06 section is not one implementation turn.
- Normally review one function, one DTO, one query, one endpoint action, or one to three closely related test cases across one to three files. Split a child further if its diff cannot reasonably be reviewed on its own.
- Complete dependencies before their caller. Write and review all agreed Part 2 unit/integration tests in Stage 2 before changing production behavior in Stages 3–5.
- Finish each unit, report its purpose, exact changes, test evidence or pending verification, and wait for user review before advancing. Keep separate statuses for test written, implementation complete, verification passed, and review accepted.
- Each checkbox is an independent review unit. Check it only after its stated work and acceptance are complete. Do not complete a parent while any applicable child is unfinished.
- Tests express agreed requirements. Do not weaken assertions, mock authoritative persistence, skip required failures, or change requirements merely to make current code pass. A test correction requires an explained review.
- **OWN-03 review amendment:** The user deferred reading/accepting the large matrix. It is a provisional planning reference, not a frozen contract or prerequisite for OWN-04. During each test-writing unit, explain the setup, action, successful result, failure result, and unchanged data in short Question / Answer prose. The user may change the test/expected behavior during that review; record the decision and affected requirement before implementation. Do not require approval of the whole table.
- Missing classes/endpoints/schema can cause initial failures. Distinguish these from assertion failures and environment problems. Missing test schema is a blocker to database execution, not passing or failing product evidence.
- New behavior discovered later requires its test before its implementation. Preserve the approved earlier cases as regression coverage.
- Search for existing functions before adding helpers. Reuse Inventory quantity arithmetic, tenant context, existing exception/message conventions, currency validation, private-image utilities, transaction/idempotency services, and scheduler conventions where appropriate.
- Follow Controllers → Services → Repositories → Database. Each service has one primary repository; access another module through its service. Public services return typed response DTOs. Keep controllers thin and messages localized in English/Myanmar.
- Replace contracts and their consumers together at activation. Do not wire an incompatible response into a live caller while waiting for another unit.
- Business callers own financial postings, Financial Account changes, primary business audit, and their transaction/replay boundary. Ownership supplies entitlement, lot/cost references, and quantity effects; it does not become another Accounting module.
- No migrations, schema refresh, development server, scheduler, or queue worker may be run by this checklist. Test execution requires the user's execution instruction and an already prepared disposable schema. This documentation request authorizes no runtime execution.
- Use real MySQL/InnoDB with independent connections for concurrency claims. SQLite and mocks do not prove row-lock correctness.
- Preserve acquisition, serialized-piece, receipt-link, and movement history. Add schema through reviewed migrations; do not delete historical movements to obtain performance.
- Avoid unrelated UI redesign and shared-service refactoring. Use separate mobile/desktop components or classes where needed.

## Agreed decisions

### Ownership and Inventory in simple words

Inventory records **physical custody**. Ownership records **what belongs to the tenant and its acquisition cost**. These are separate facts.

| Event/state | Tenant ownership | Physical custody |
|---|---|---|
| Purchase fully paid, goods not received | Owned; retain purchase quantity/cost | No stock receipt yet |
| Goods received before purchase is fully paid | Not acquired through that unpaid purchase | Goods may be physically held; sale/pledge remains unavailable in 2.0 |
| Fully paid goods received | Already owned; receipt must not acquire them again | Stock received and linked to the original acquisition |
| Quick Buy | Fully paid acquisition | Immediate full receipt in the same business transaction |
| Quick Sell | Ownership reduced | Goods issued immediately in the same business transaction |
| Pledge handed to lender | Still owned, but pledged | Held by lender, separately from shop stock |
| Pledge returned | Still owned; release the pledge | Return to the selected shop location |
| Confirmed forfeiture | Ownership and the matching pledge reduced | Remove the tenant's lender-held custody record through the loan workflow |
| Future sale before delivery | May cease to be owned before dispatch | May remain physically at the shop; deferred workflow |

- **The earlier proposal to require physical receipt before acquisition is superseded.** Full purchase payment, not receipt, establishes purchase ownership.
- Partial purchase payment acquires none of that purchase until its full bill is settled. Selling received but unpaid goods is an explicit future placeholder, not a 2.0 exception.
- Paid goods may be recorded before their exact serial numbers are known. Later receipt attaches actual serialized pieces to the existing acquisition lot. Show a clear reminder/action when received pieces still need linking.
- Sale and pledge require both eligible tenant ownership and actual received, linked, available physical goods. Unreceived or unlinked goods cannot be sold/pledged. Aggregate item totals alone do not prove the selected lot/pieces are eligible.
- A received piece cannot have two current ownership lots. Keep historical links when it leaves ownership; valid later reacquisition creates a new lot without erasing earlier history.
- Keep acquisition lots immutable: original source, acquired date/quantity, unit cost, estimated value, and currency remain historical snapshots. A dedicated correction workflow is outside this part.
- Keep one Ownership aggregate per tenant/Inventory item. Preserve depleted aggregates and old lots; a later valid acquisition adds a new lot.
- Purchase ownership must reference a payment-qualified purchase source, not depend solely on `PurchaseReceiptLine`. Receipt has its own source and links back to the acquisition. Canonical source details are reviewed in OWN-02 before tests are written.
- Only Quick Buy/Quick Sell are enabled for Purchasing/Sales in 2.0. Paid-before-receipt is a supported Ownership foundation; this does not enable staged purchasing or delivery screens.

### Quantities, costs, and reads

- Reuse exact three-decimal quantity arithmetic; reject a `0.001` shortfall. UNIQUE requires one; SERIALIZED requires whole quantities and distinct exact pieces when those pieces are known.
- Implementation defaults accepted in OWN-02: exact four-decimal cost/value strings matching current storage; active visible currency; non-negative costs; a zero-total purchase counts as settled when its qualified source confirms nothing is due. Never create fake cash/payment rows for zero totals.
- Keep costs/currencies per lot. Do not sum different currencies into one monetary total. Existing estimated values are snapshots, not automatically changing market prices.
- Owned availability is `owned − sale reserved − pledged`. This differs from physically available stock and from the final quantity eligible for sale/pledge.
- Paginate item summaries, lots, movement history, and serialized-piece/link candidates. Defaults accepted in OWN-02: 25 per page, maximum 100, stable ordering, and the filters/wire contracts recorded below.
- Batch balances for the requested page. Do not run one balance/detail query for every item, lot, or serialized piece.
- Reuse Inventory photo URLs/expiry and bundled placeholder behavior in Ownership rows/detail. No new upload system or copied photos.

### Monthly Ownership summaries — included scope amendment

Monthly summaries are required in Part 2. They replace the earlier exclusion of Ownership summaries. Save item/lot closing quantities and calculate current balances from the latest completed summary plus uncovered newer movements. Retain original history.

- Summarize owned, sale-reserved, and pledged quantities; derive available quantity from those components. Include paid but unreceived goods in owned balances.
- Keep physical custody in Inventory. Keep current receipt links, serialized identity, and source-specific commitments authoritative and live rather than treating a monthly total as proof of identity/source eligibility.
- Do not rebuild balances from the first movement on normal reads or monthly reruns. Initial publication without an older summary necessarily reads the affected item's retained history once; missing recent months use an older summary plus a longer tail.
- Monthly quantities are not financial postings or an Accounting close. Lot cost/currency snapshots remain separate; no cross-currency valuation total is introduced.
- Follow the Inventory/financial summary pattern for tenant-local month boundaries, UTC cutoffs, movement watermarks, append coordination, queue/logging, duplicate-job safety, and explicit recovery.

### Module boundaries and future placeholders

| Area | Included here | Later owning part / placeholder |
|---|---|---|
| Purchasing | Ownership acquisition/linking contracts and coordinated caller adaptations | Part 4 completes fully paid Quick Buy orchestration and end-to-end acceptance |
| Sales | Ownership reservation/reduction contracts, exact lot/piece eligibility, caller adaptations | Part 5 completes Quick Sell and end-to-end acceptance |
| Pawn | Source-compatible acquisition foundation; preserve existing caller compatibility | Part 6 completes expired acquisition, redemption, and their financial/custody effects |
| Packing | Preserve identities/lot references needed by callers | Part 7 owns pack composition, pack restrictions, and whole-pack workflows |
| Business Loan collateral | Ownership pledge/release/forfeiture primitives and source references | Part 8 owns lender movement, collateral records, settlement, and complete workflows |
| Supplier credit | Keep ownership separate from receipt and document the exception boundary | Future: authorized sale of unpaid received goods, supplier payable preservation/settlement, and loan-funded payment |
| Delivery | Keep ownership and custody independent | Future: sold/not-owned goods still held pending dispatch; distinct dispatch/return rules |
| Returns/manual acquisition | Preserve necessary existing internal callers with regression coverage | Do not activate public returns, refunds, or `ownership_manual_acquisition` |

Full Purchasing/Sales business integration testing remains user-deferred to Parts 4/5. Part 2 uses real Ownership + Inventory integration, API tests, and narrow caller contract checks. No passing helper test establishes that a deferred complete business workflow is correct.

## Dependency map

```text
Requirement analysis / user review / contract and scenario decisions
    → complete agreed unit + integration + API + concurrency test writing
    → initial results classified / test review acceptance
    → feature gates and permission boundaries
    → messages / schema / typed contracts / repository queries
    → exact balance / linking / locking / validation / mutation helpers
    → acquisition / receipt linking / sale / pledge orchestration
    → monthly summary service / job / recovery orchestration
    → thin controllers / API / coordinated callers / frontend
    → confirmations / measured query plans / regression evidence / user acceptance
```

These are six top-level stages, not six implementation batches. Child IDs below are the human-review boundaries. Monthly tests are written in Stage 2; monthly schema/queries belong to Stage 4; scheduled orchestration belongs to Stage 5.

## Status register

Suggested states: Not started, In progress, Tests written / awaiting review, Implemented / awaiting review, Accepted / verification pending, Complete, Blocked.

| Step | Deliverable | Status |
|---|---|---|
| OWN-01 | Requirement and existing-flow review | Complete — analysis accepted before OWN-02; no runtime verification implied |
| OWN-02 | Source, data, API, and quantity decisions | Complete — OWN-02a–h Q&A accepted; implementation/tests not started |
| OWN-03 | Acceptance matrix and test boundaries | Review deferred by user — provisional reference; expectations reviewed per test during Stage 2 |
| OWN-04 | Test harness and fixtures | Complete — OWN-04a–d implementation/review accepted; fixture/worker runtime verification pending |
| OWN-05 | Unit tests | Not started |
| OWN-06 | Acquisition integration tests | Not started |
| OWN-07 | Receipt/serialized linking integration tests | Not started |
| OWN-08 | Sale and pledge quantity integration tests | Not started |
| OWN-09 | API/read/gate tests | Not started |
| OWN-10 | Transaction/idempotency/concurrency tests | Not started |
| OWN-11 | Monthly summary tests | Not started |
| OWN-12 | Initial test results and test acceptance | Not started |
| OWN-13 | Feature gates and permission wiring | Not started |
| OWN-14 | Messages and exact arithmetic foundations | Not started |
| OWN-15 | Schema, models, constraints, indexes | Not started |
| OWN-16 | Typed command/read/result contracts | Not started |
| OWN-17 | Batched checkpoint-backed balance queries | Not started |
| OWN-18 | Source, receipt, and serialized-link queries | Not started |
| OWN-19 | Ordered locking/current-read helpers | Not started |
| OWN-20 | Validation helpers | Not started |
| OWN-21 | Mutation/transaction/idempotency helpers | Not started |
| OWN-22 | Acquisition orchestration | Not started |
| OWN-23 | Receipt and exact-piece linking orchestration | Not started |
| OWN-24 | Sale reservation/release/reduction orchestration | Not started |
| OWN-25 | Pledge/release/forfeiture orchestration | Not started |
| OWN-26 | Read orchestration | Not started |
| OWN-27 | Monthly summary orchestration | Not started |
| OWN-28 | Scheduled summary and recovery | Not started |
| OWN-29 | Controller/API activation | Not started |
| OWN-30 | Existing caller adaptations | Not started |
| OWN-31 | Frontend connections | Not started |
| OWN-32 | Acceptance verification | Not started |
| OWN-33 | Query plans and performance confirmation | Not started |
| OWN-34 | Complexity review and completion record | Not started |

## Stage 1 — Requirement analysis

### OWN-01 — Review current flows against agreed behavior

**Depends on:** This session's confirmed flow and Part 2 module boundaries.

**Files:** `app/Services/TenantModule/OwnershipService.php`, `app/Repository/OwnershipRepository.php`; inspect each business caller separately.

- [x] **OWN-01a:** Trace existing acquisition and payment/receipt timing. Record the current receipt-triggered acquisition mismatch without changing code.
- [x] **OWN-01b:** Trace sale reservation, release, reduction, and return callers; document which caller owns each transaction and financial effect.
- [x] **OWN-01c:** Trace pledge/release/forfeiture helpers; record absent availability/source safeguards and the fully pledged forfeiture defect.
- [x] **OWN-01d:** Review Ownership list/detail/history and frontend; record unbounded collections, repeated queries, missing reserved fields, and stale-selection risks.
- [x] **OWN-01e:** Present the flow findings and included/deferred boundary to the user; obtain requirement review acceptance.

#### OWN-01a — Purchase payment, receipt, and acquisition findings

**Prepared:** 2026-10-20. **Review:** Accepted by the user through “ok next” before the subsequent full OWN-01 batch instruction. This is static code analysis, not runtime verification; no production code or tests changed.

**Current flow:** Create/confirm purchase → record payment if tender is positive → receive all goods → create Ownership from each received line. Payment and acquisition are different steps, but acquisition currently depends on receipt instead of full settlement.

| Function / record | Current behavior found in code | Difference from agreed flow |
|---|---|---|
| `PurchasingService::quickPurchase` | Creates and confirms the order, calls `recordPayment` only for positive tender, then always calls `recordReceipt` for all lines inside the existing business transaction. | No comparison requiring tender to equal the complete order total was found in this function or `PurchasingController::quickPurchase`. Underpayment/zero tender against positive-cost goods is not rejected at this entry boundary. This is a static finding, not a reproduced runtime result. |
| `PurchasingService::recordPayment` | Locks the order, validates payable status/account/currency, prevents overpayment, records supplier advance/accounting/cash outflow and audit. Rejects this payment path after any receipt. | Full payment does not create Ownership. The existing post-receipt payment restriction is a future supplier-debt settlement boundary, not a new workflow to implement in Part 2. |
| `PurchasingService::recordReceipt` | Requires a confirmed order, receives Inventory, then calls `OwnershipService::acquireFromSource` once for each receipt line, before calculating the unpaid receipt value and supplier payable. | No full-payment prerequisite before acquisition. Received goods can acquire Ownership in this code path even when purchase payment is incomplete. Receipt currently creates ownership rather than linking to ownership already acquired by payment. |
| `OwnershipService::acquireFromSource` | Purchasing source allowlist accepts `PurchaseReceiptLine`; Pawn accepts `PawnCollateralItem`. It passes accepted type/code values into acquisition persistence. | Purchasing cannot supply a canonical paid purchase-line acquisition source yet. The method does not resolve the source record or verify its payment status. A source type/code string alone must not become proof of full payment. |
| `OwnershipService::recordAcquisition` | Resolves/locks an existing Inventory item, checks quantity and duplicate source, then writes an aggregate if needed, acquisition lot, and `ACQUISITION` movement. The purchase caller supplies `received_at` as acquisition date. | Paid acquisition before receipt needs a stable item identity without a physical movement, plus the agreed settlement/acquisition date instead of forcing receipt date. This helper does not itself post Inventory movements; its purchase caller currently supplies the receipt-created identity. |
| `PurchaseOrderLine` / `PurchaseReceiptLine` | Order creation does not assign an Inventory identity. Receipt assigns/reuses it; receipt lines store Inventory item, serialized codes, owned-item code, and lot code. The caller currently selects the first returned lot as its receipt lot. | Preserve a stable purchase-line acquisition reference before arrival and link later receipts to the exact returned lot, not whichever lot happens to be first in a full-item response. Do not acquire again at receipt. |

**Evidence inspected:** `PurchasingController::quickPurchase`; `PurchasingService::{quickPurchase,createOrder,recordPayment,recordReceipt,runIdempotent}`; `OwnershipService::{acquireFromSource,recordAcquisition}`; `InventoryService::findForOwnershipByCode`; `PurchaseOrderLine` and `PurchaseReceiptLine`. References are function/model names so later line shifts do not invalidate this record.

**Required behavior for later units:**

1. Purchasing establishes that the whole purchase is settled, then acquires each purchase line exactly once. Positive-total Quick Buy must reject insufficient payment before committing any effects; zero-total rules remain subject to OWN-02e review.
2. Acquisition preserves item/source/quantity/cost/currency and the reviewed acquisition date without receiving stock or inventing serial numbers.
3. Receipt adds physical stock and attaches the receipt quantity/exact pieces to the existing acquisition lot. It creates no second acquisition or changed cost snapshot.
4. Keep payment, Inventory, Ownership, and required financial effects atomic in Quick Buy. Ownership timing changes must not silently duplicate supplier-advance/stock-Asset postings; financial orchestration and complete acceptance remain Purchasing Part 4 responsibilities.
5. Preserve supplier-credit and later delivery as deferred workflows. Do not fix their existing behavior or expose their APIs during this analysis unit.

**Follow-up map:** Paid-source validation/date and pre-receipt identity decisions → OWN-02a/b/e; tests → OWN-06a/b/d and OWN-07a/b/c; helpers/orchestration → OWN-20a, OWN-22, OWN-23; coordinated Quick Buy/receipt adaptation → OWN-30a/b; complete Purchasing payment/financial acceptance → Part 4. No later unit is accepted by recording this map.

**Verification:** Read-only source inspection only. No tests, database queries, migrations, development server, scheduler, or worker executed. Documentation whitespace check is recorded separately in the review log.

#### OWN-01b — Sales reservation, release, reduction, and return findings

**Batch authorization:** The user requested “just perform full 01”, authorizing OWN-01b–e analysis together. This changes the one-child-per-turn limit for this analysis batch only. It does not authorize implementation, test execution, or automatic review acceptance.

**Current sales flow:** Create draft → confirm/reserve Inventory and Ownership → receive customer payment when supplied → immediate fulfillment issues Inventory, releases Ownership reservation, and reduces owned quantity. Cancellation releases unused reservations. Existing delivery/return functions use related helpers but remain deferred public workflows under the agreed release boundary.

| Function / boundary | Current behavior | Finding / requirement |
|---|---|---|
| `SalesService::transition` / `reserveLine` | Confirmation prelocks the order's complete Inventory item set. Allocation combines FIFO Ownership lot quantities with active/sellable physical locations. It reserves Inventory, calls `OwnershipService::reserveSale`, and persists Sales reservation records. Serialized codes are distinct across allocations. | Useful physical availability and ordering safeguards already exist. However physical pieces are selected by Inventory item/location, not proven membership of the selected acquisition lot. Paid-but-unreceived lots will require explicit receipt/lot matching rather than combining independent item totals. |
| `OwnershipService::reserveSale` / `changeQuantity` | `SALE_RESERVE` appends positive reserved delta under aggregate/lot locks. | The shared guard checks owned reductions, pledged changes, and negative reserved changes, but omits a positive reserved-versus-available check. Caller allocation is not a substitute for enforcing this invariant at the mutation boundary. |
| `SalesService::transition` cancellation / `OwnershipService::releaseSaleReservation` | Cancellation releases linked Inventory reservation and Ownership reserved quantity, then marks the Sales reservation released. | Ownership checks total reserved quantity for the lot, not outstanding quantity for the supplied sale source. Its source type/code is recorded but not resolved into an authoritative same-source commitment. Another sale's reservation must not be releasable. |
| `SalesService::completeImmediately` and `recordDelivery` | Caller creates allocation/cost snapshots, posts cost/stock-Asset effects, issues Inventory, calls Ownership release, then calls `reduceForSale`. All are inside the business transaction. | Release and reduction currently share an outer transaction, so the sequence is not evidence of a partial committed sale by itself. Replace with the reviewed same-source atomic consumption contract while preserving caller rollback. Existing generated Ownership keys remain on reductions and need review at OWN-21d/30c. |
| `OwnershipService::reduceForSale` | Checks lot-wide unreserved/unpledged availability and appends a negative owned delta. Inputs are floats and no exact piece list is passed. | Needs exact quantities and the correct sale/lot/piece membership. A recorded `SaleLine` source string alone does not validate the actual allocation. |
| `SaleReturnService::create` / `restoreForSaleReturn` | Return caller validates the delivered allocation and unreturned quantity/pieces, receives the same pieces into Inventory, restores the original Ownership lot, and handles cost reversals, receivable credit, and cash refunds. | Caller has important source/quantity guards. Ownership's positive owned-delta helper itself validates only positive finite quantity plus source type/nonempty code, not the original sale/return allowance. Preserve caller behavior while making the internal boundary safe; do not enable public returns here. |
| `PurchasingService::recordReturn` / `reduceForPurchaseReturn` | Purchase-return caller issues Inventory and reduces the receipt's referenced Ownership lot, with caller-owned settlement/accounting. | Preserve exact original-lot/source compatibility when receipt acquisition is separated from payment. Complete return workflows remain deferred. |
| `SalesController::quickSale` / `SalesService::quickSale` / `completeImmediately` | Tender can be absent/zero; positive tender is permission checked. Fulfillment computes an unpaid remainder and invokes receivable handling. | Reviewed code does not establish the agreed fully paid-only Quick Sell rule: it still contains credit-sale behavior. Record for Part 5 payment/gate acceptance; Part 2 must not claim that safe Ownership helpers alone fix complete Quick Sell. |

**Transaction and financial ownership:** `SalesService::runIdempotent` owns the enclosing sale transaction and optional public replay; confirm/cancel/fulfillment use nested transactions within it. Sales owns sale/payment/receivable records, Revenue, COGS, stock-Asset relief, account receipt/change, and completion audit. `SaleReturnService::runIdempotent` owns the return transaction/replay and the return financial/audit effects. Inventory owns stock validations/movements within those callers. Ownership currently adds its own wrapper/nested transactions and ledger movements; it does not directly post sale cash/accounting. Nested transaction boundaries must be reviewed, not removed solely because they are nested. Lock behavior and rollback remain unverified at runtime.

**Evidence inspected:** `SalesService::{createOrder,transition,reserveLine,quickSale,completeImmediately,recordDelivery,runIdempotent}`; `SalesController::quickSale`; `OwnershipService::{fifoLots,reserveSale,releaseSaleReservation,reduceForSale,restoreForSaleReturn,reduceForPurchaseReturn,changeQuantity,runIdempotent}`; `SaleReturnService::{create,returnUnitCodes,runIdempotent}`; the purchase-return call site; `OwnershipRepository::lotBalances`.

**Follow-up map:** Source/consumption decisions → OWN-02c/d; tests → OWN-07f, OWN-08a–d/h, OWN-10a–g; helpers → OWN-17e, OWN-18d, OWN-19/20d/f, OWN-21; orchestration/callers → OWN-24/30c/d; complete fully paid Quick Sell and financial verification → Part 5. Read/query findings are consolidated under OWN-01d.

#### OWN-01c — Pledge, release, and forfeiture findings

**Current helper flow:** `reservePledge` leaves owned unchanged and increases pledged; `releasePledge` decreases pledged; `reduceForfeiture` normally decreases owned and pledged together. All delegate to `changeQuantity` and its transaction/replay wrapper.

| Boundary | Finding | Required behavior |
|---|---|---|
| Pledge creation | Positive pledged quantity is checked against lot-wide available ownership, but the helper does not check received quantity, exact serialized lot membership, live custody, or an actual collateral source record. | Use the reviewed Ownership eligibility and caller Inventory checks; pledge only available owned and received/linked goods. Keep owned quantity unchanged. |
| Pledge release | Negative pledged quantity is checked against total lot pledged quantity. No remaining-pledge calculation bound to the specified collateral source exists at this boundary. | Release only that source's outstanding quantity/pieces. A new request key or arbitrary source code must not release another loan's pledge. |
| Fully pledged forfeiture | `changeQuantity` applies its negative-owned guard against unpledged availability before the negative-pledged guard. With owned 10 and pledged 10, available is 0 and forfeiting the matching 10 rejects. | Validate against the same source's pledged quantity, then reduce owned and pledged atomically. Preserve unrelated reservations/pledges and original cost/history. |
| Optional unpledged forfeiture | `reduceForfeiture` exposes `fromPledge = false`. | Resolve whether any legitimate supported caller needs this in OWN-02d. Do not let a public request select it or bypass pledged-source rules. No new unpledged disposal workflow is approved by this finding. |
| Business Loan wiring | Search of application call sites found only these three method declarations, with no application callers. `TenantBusinessLoanService` handles loan creation/payment/interest/accounting and has no Ownership/Inventory collateral orchestration. | Part 2 builds/tests the Ownership primitives. Part 8 supplies actual collateral records, lender custody movement, return, maturity confirmation, and non-cash settlement. Do not claim loan collateral is implemented by the helpers' existence. |
| Lock/source architecture | Helpers lock Ownership aggregate then lot. There is no same-helper Inventory prelock or canonical current source commitment; acquisition instead enters through an Inventory identity lock. | Review cross-module order before caller activation. This is an ordering gap to analyze/test, not a runtime deadlock claim. All authoritative appends and monthly summaries must later share the reviewed aggregate anchor. |

Ownership pledge helpers currently post no lender movement or loan cash/accounting themselves. That separation is appropriate; complete physical custody/financial effects must belong to the Part 8 caller. Existing float arithmetic and full-history lot sums also affect these helpers.

**Evidence inspected:** `OwnershipService::{reservePledge,releasePledge,reduceForfeiture,changeQuantity,recordAcquisition,runIdempotent}`; `OwnershipRepository` aggregate/lot lookups and balances; `TenantBusinessLoanService` dependencies/operations; application-wide search for pledge/release/forfeiture helper call sites; existing `OwnershipMovement` fields/casts.

**Follow-up map:** Source commitments/allowed transitions → OWN-02d; tests → OWN-08e–g and OWN-10e/f; locking/validation/mutations → OWN-18d, OWN-19, OWN-20e, OWN-21; primitives → OWN-25; complete lender custody and settlement → Part 8.

#### OWN-01d — Ownership reads, API, and frontend findings

| Area | Current behavior found | Needed change |
|---|---|---|
| Item register and lots | `ownedItems` loads all matching aggregates and eager-loads all acquisition lots. `list` shapes each through `ownedItemResource`. Detail also includes every lot. | Paginate item/lot reads separately. Summary rows must not include all lots or pieces. Keep depleted and paid/unreceived records accessible. |
| Balance calculations | `movementBalances` sums the complete tenant/item ledger; `lotBalances` sums the complete tenant/item/lot ledger, casting results to floats. Resource shaping calls one item sum, one sum per lot, and Inventory detail per item. `fifoLots` also sums per lot. | Exact batch item/lot balances backed by monthly checkpoint plus tail. Remove per-row queries and repeated inception sums. No Ownership checkpoint model/query/job was found in application code. |
| Movement history | `movements` returns all item movements ordered by ID. API resource includes owned/pledged deltas but omits `reserved_delta`. | Paginate stable history and expose reserved changes/source provenance so reservation movements are understandable. |
| Response contracts | Ownership list/detail/mutations return arrays with internal aggregate/lot IDs alongside codes. Inventory options already uses a typed, paginated Inventory summary contract. | Preserve/reuse the working Inventory option pagination. Replace Ownership public arrays with reviewed typed contracts and public codes; coordinate all consumers together. |
| Backend/frontend field mismatch | Item/lot resources contain `reserved_quantity`, and location projections contain reservation/availability/state fields. Frontend Ownership types/screens omit some of these fields; movement type also omits backend `source_code` and reserved delta is absent in the backend resource itself. | Align contracts and show owned, reserved, pledged, received/awaiting receipt, and eligible quantities with clear meanings. Do not confuse owned availability with physically ready stock. |
| Detail selection/history race | `selectOwnedItem` stores the list row directly and starts an unguarded history request. It does not use the existing detail API or invalidate an earlier item's history request. Register search/options loading already has request-generation protection. | Reuse the existing request protection approach for selection/detail/history. Fetch fresh detail, clear/invalidate old history, and prevent item A's slow response replacing item B's history. |
| Paging/UI state | Only the acquisition Inventory selector is paginated. Owned list, embedded lots, and history lack server paging. | Connect the new independent pages without reusing stale selections; preserve the existing bounded selector behavior. |
| Receipt linking and lender custody | No Ownership receipt-link API/action/reminder or exact serialized lot-membership UI exists. Current views expose item-level locations and pledged quantities, not a complete lender-collateral workflow. | Add pending-link/reminder flow and exact-piece candidates in Part 2. Actual lender custody workflow/view data remains Part 8; do not manufacture lender stock from an Ownership total. |
| Photos | Inventory detail already supplies photo information, but `ownedItemResource` does not copy image URL/expiry into the Ownership response. Ownership types/page have no photo/placeholder display. | Reuse the accepted Inventory photo/expiry and bundled placeholder; no copied image or upload feature. |
| Replay and decimal inputs | Frontend `acquire` and expired transfer generate a new idempotency key for every invocation. Forms use `Number(...)` for quantity/cost values. | Retain the same key for a retry of unchanged intent and use exact decimal strings at new contracts. Preserve existing action gating and keep Pawn conversion work scoped to Part 6. |
| Manual acquisition gate | Ownership API acquisition route and frontend route/navigation check general Ownership/Inventory features and create permission. `ownership_manual_acquisition` was found in the plan, not registered/wired in the inspected config/migrations/routes. | OWN-13 must register/restrict this deferred public operation and hide/direct-route-protect its UI. Existing visibility is not permission to activate it under 2.0 scope. |
| Layout and messages | Existing Ownership workspace/forms use responsive CSS; expired collateral already has separate mobile/desktop classes. Ownership page contains literal English labels/feedback rather than a locale hook. | Extend the existing layout with the required separate responsive controls and translated feedback. Do not redesign the whole page. |

**Evidence inspected:** `OwnershipRepository::{ownedItems,ownedItemByCode,movements,movementBalances,lotBalances}`; `OwnershipService::{list,detailByCode,fifoLots,ownedItemResource,movementsByCode,inventoryOptions}`; `OwnershipController`; Ownership API routes; Ownership frontend service/types/page/CSS, router, and navigation; package feature configuration/migration references. No database plans, browser behavior, or permission behavior were executed.

**Follow-up map:** API/UI decisions → OWN-02f/g/h; tests → OWN-05d, OWN-09, OWN-11; gate → OWN-13; queries/DTOs → OWN-15–18; reads/monthly/API/frontend → OWN-26–29/31; measured evidence → OWN-32/33.

#### OWN-01e — Consolidated requirement review and boundaries

**Analysis outcome:** OWN-01a–e source review is complete and accepted through the user's “next step : all 02” instruction. This does not mean Part 2 requirements/contracts in OWN-02 are decided, tests are written, features are implemented, or runtime verification passed.

The required Part 2 changes remain:

1. Full-payment-qualified acquisition independent of receipt, followed by receipt-to-lot/exact-piece linking without double acquisition.
2. Exact source-bound Ownership reservations/reductions/pledges/releases/forfeiture, including fully pledged forfeiture and no use of another source's quantities.
3. Shared transaction/locking/replay contracts with callers; preserve caller-owned accounting/account/audit and resolve duplicates only with tests first.
4. Monthly item/lot checkpoints wired into current balances/FIFO, bounded reads, and live source/receipt/piece checks.
5. Typed paginated APIs and consistent UI fields, fresh detail/history, linking reminders, images/placeholders, exact input values, retry keys, translation, and disabled manual acquisition.

**Boundary confirmation:** Part 2 tests Ownership + Inventory, not complete Purchasing/Sales business workflows. Quick Buy's missing full-payment rule belongs in Part 4 completion and Quick Sell's credit-capable completion in Part 5; record them as required later acceptance, not optional cleanup. Pawn conversion remains Part 6, packing Part 7, and actual loan collateral/lender custody/financial settlement Part 8. Unpaid-goods sale and sold-before-delivery remain future placeholders. No unrelated code is changed by this review.

**Next dependency after review:** OWN-02a, beginning with payment-qualified source evidence and a canonical purchase-line acquisition reference. Do not invent that reference during test writing or reuse the receipt source merely because it is currently available.

**Verification for the entire OWN-01 batch:** Static file/search inspection only. No executable tests were written/run, no production files changed, and no database queries, migrations, services, jobs, or workers executed. Findings describe code paths and missing safeguards; concurrency, actual financial outcomes, and observed UI behavior require later tests/confirmation. Documentation-only consistency/whitespace checks passed.

### OWN-02 — Review contracts and data decisions before writing tests

**Depends on:** OWN-01 acceptance.

**Files:** Existing Ownership/Inventory/Purchasing models, DTOs, routes and schema; the decisions are recorded here before production edits.

- [x] **OWN-02a:** Specify fully paid acquisition evidence and a stable purchase-line acquisition source distinct from receipt source. The source service validates actual tenant/payment eligibility; client strings cannot assert that payment occurred.
- [x] **OWN-02b:** Specify how a paid item receives a stable item identity before arrival without creating physical movements/serialized pieces. Reuse or narrowly extend Inventory identity creation; do not require Catalog or deduplicate by name.
- [x] **OWN-02c:** Specify receipt-to-lot quantity allocation and serialized-piece membership/history. Include partial foundation receipts, unknown identifiers, duplicate linking, and later reacquisition. Do not add staged purchase UI.
- [x] **OWN-02d:** Specify source-bound reservation/pledge records so one sale/loan cannot release or consume another's quantity. Agree exact atomic sale consumption and pledged-forfeiture rules.
- [x] **OWN-02e:** Review decimal bounds, four-decimal cost defaults, zero-total settlement, currency policy, acquisition date, and immutable-lot behavior. Record accepted defaults and any changed decisions.
- [x] **OWN-02f:** Specify paginated summary/detail/lot/history/link-candidate wire contracts, filters, stable ordering, error codes, and permissions. Use public business codes; keep internal IDs internal.
- [x] **OWN-02g:** Review monthly schema and balance dimensions using [Monthly Ownership checkpoints](#monthly-ownership-checkpoints); include how current source commitments and receipt links avoid full-history scans.
- [x] **OWN-02h:** Review UI labels and linking reminder/action, photo placeholder, awaiting-receipt state, lender-held state, and historical/depleted views. Obtain acceptance before encoding these expectations in tests.

#### OWN-02 decision record — Question / Answer format

**Prepared:** 2026-10-20. **Accepted:** The user approved all OWN-02 answers through “ok fine by me , next 03”. The user requested Question / Answer wording. Labels **Confirmed answer** and **Proposed answer** below preserve how each answer was originally presented; both are now accepted within the stated module boundaries. All OWN-02 checkboxes are checked for design acceptance only. No production contracts/schema/tests have changed.

#### OWN-02a — When and from which purchase do we record Ownership?

**Question: When do purchased goods become ours?**

**Confirmed answer:** When the complete purchase is fully paid. They do not need to have arrived. Part payment acquires none of that purchase. Quick Buy remains fully paid plus immediate receipt; unpaid-goods sale remains deferred.

**Question: Which purchase reference should an acquisition keep?**

**Proposed answer:** Use `PURCHASING / PurchaseOrderLine / <line code>` as the canonical acquisition source. Create one acquisition lot per purchase line after the complete order is settled. Keep order/line/currency/cost/quantity references as snapshots. Each receipt keeps its separate `PurchaseReceiptLine` reference and points back to this lot. Enforce unique tenant/module/type/source-code acquisition in the database. Do not use a payment row as the lot source: several payments can settle one order.

**Question: Can a request simply say “this purchase is paid”?**

**Proposed answer:** No. A narrow `PurchasingOwnershipSourceService`, using the Purchasing repository and no dependency on Ownership, resolves and validates the order/line under the caller's transaction. Ownership calls that source service; do not inject PurchasingService back into Ownership and create a constructor dependency cycle. The validated source result contains item metadata/reference, exact ordered quantity, snapshotted unit cost/currency, effective settlement date, and settlement evidence. It accepts no client-owned `is_paid` flag. It checks same tenant, correct line/order, confirmed/non-cancelled purchase, and the entire payable total settled using exact values. An underpaid or overpaid Quick Buy is rejected under the fully paid-only caller contract; tender/change policy is not invented in Ownership.

**Question: What happens if the same acquisition is submitted again?**

**Proposed answer:** A completed same-key/same-payload request replays its original result. A different key does not permit another acquisition of the same source: reject duplicate source acquisition. A changed payload with the original key conflicts. Return the newly affected lot explicitly; never choose `lots[0]` from full item history. For internal calls, return replayed data rather than throwing the public HTTP replay response into the parent transaction.

#### OWN-02b — How can we own goods before they arrive?

**Question: Must an Inventory record mean physical stock already exists?**

**Proposed answer:** No. Keep a stable Inventory item identity with zero stock before arrival. The record describes the goods; only an actual Inventory receipt movement adds physical quantity. Ownership remains linked to that identity, so no nullable item reference or invented stock movement is needed.

**Question: How do we create that identity without duplicating existing code?**

**Proposed answer:** Expose a narrow internal Inventory identity operation after factoring/reusing `createInventoryItem` metadata validation. Use a typed identity request, not a fake zero-quantity receipt. Reuse existing Catalog/unit/tenant checks and `createItem` persistence. Validate acquisition quantity in Ownership/source qualification. Generate no serialized piece records, movements, reservations, or financial records from identity creation.

**Question: When should an old item identity be reused?**

**Proposed answer:** Only when an explicit same-tenant existing item reference matches its tracking mode, unit, and applicable Catalog reference. Never merge goods by name. UNIQUE lines represent one specific item; create a new identity for a different physical item, and reuse an old one only for an explicitly identified valid reacquisition after prior ownership ends. QUANTITY/SERIALIZED purchase lines may explicitly reuse a matching item identity. Persist the selected identity on the purchase line before acquisition.

#### OWN-02c — How do we connect received goods to their purchase?

**Question: Does receiving goods give us Ownership again?**

**Confirmed answer:** No. Receipt adds physical stock and links goods to the already recorded purchase lot. It must not increase owned quantity or change the original cost/date/currency.

**Question: What must a receipt link identify?**

**Proposed answer:** Identify the acquisition lot, exact purchase receipt line, linked quantity, and actual serialized codes when applicable. Resolve tenant/item/purchase association server-side. One receipt line has one canonical purchase-line lot; never move receipt quantity to another purchase merely because its item name matches. Multiple partial links are allowed internally up to that receipt's still-unlinked quantity and the lot's original acquisition quantity across its original purchase receipts; this does not expose staged purchasing UI. Do not use outstanding unreceived quantity as link capacity: goods already received must still be linkable. For SERIALIZED links, selected distinct received pieces must equal the quantity and belong to that receipt/item.

**Question: What if serial numbers are unknown when we pay?**

**Confirmed answer:** Record purchased quantity/cost now. Record actual pieces when received, then link them to the lot. Inform the user about received pieces still needing a link; unlinked pieces cannot be sold/pledged.

**Question: Must the user manually link every Quick Buy?**

**Proposed answer:** No. Automatically link the trusted immediate receipt when purchase/lot/pieces are unambiguous. Otherwise keep the successful receipt, show “Received items need linking”, and offer the restricted linking action. Do not silently choose a lot or block the record of physical receipt merely because later manual resolution is needed. Quick Buy's trusted automatic-link failure is an operation failure and rolls back the whole Quick Buy; a successful Quick Buy must not leave unresolved pieces. Unexpected mixed/legacy receipts are not an excuse to auto-acquire ownership.

**Question: How do we stop one piece belonging to two lots?**

**Proposed answer:** Add receipt-link records plus serialized-membership history under Ownership. Each membership records lot, receipt link, actual Inventory serialized item, start, and optional end. Enforce one current membership per tenant/piece in MySQL, using a unique nullable current-piece key derived from whether the membership has ended. Sale/forfeiture ends current membership but keeps history. A valid later purchase opens membership in a new lot; an authorized sale return restores the original lot through a new membership episode. A receipt link is not deleted on sale.

**Question: How do ordinary quantities remain tied to a received purchase lot?**

**Proposed answer:** Keep exact linked quantity and still-owned received quantity on `ownership_receipt_links`, updated atomically by the same acquisition/issue/return caller. The original linked quantity is historical; its `remaining_owned_quantity` tracks the still-owned received portion. Sales consume those source allocations; returns restore their original allocations, never exceeding the original issued quantity and without another original-purchase receipt. Keep issue/return source references for history. Do not repeatedly sum all historical sales to establish a link's remaining quantity. These are current source-allocation records, not a second aggregate Ownership balance table. For QUANTITY goods, physical units are interchangeable within the same item/unit and eligible locations; FIFO costing still targets receipt-backed lot quantities. SERIALIZED goods require the exact pieces. This proposed distinction requires user review before tests.

#### OWN-02d — How do sales and loans change Ownership?

**Question: What must be true before a sale or pledge?**

**Confirmed answer:** The tenant owns the goods, they have been received, they are correctly linked, and they are available. Exclude existing sale reservations, pledges, and location/custody restrictions. Being owned while awaiting arrival is insufficient.

**Question: How do we reserve goods for a specific sale or loan?**

**Proposed answer:** Persist a current Ownership commitment bound to tenant, lot, source type/code, kind (`SALE` or `PLEDGE`), and exact original/remaining quantity; keep exact committed pieces separately where applicable. A source may span several lots, each with its own commitment. Opening a commitment appends the corresponding Ownership movement. Partial release/consumption updates that same commitment and appends history. Database constraints and shared locks prevent duplicate active piece commitments. A different source cannot borrow or release its balance.

**Question: What happens when Quick Sell completes?**

**Confirmed answer:** The goods stop belonging to us and leave Inventory immediately, together in one business transaction.

**Proposed implementation:** Consume the sale's own commitment in one Ownership operation: reduce owned and reserved together, end the affected piece memberships, and consume the matching received allocation. The caller performs Inventory issue, payment/financial records, and completion audit in the same outer transaction. Cancellation releases only outstanding reservations; it does not receive goods or create ownership. A separate internal unreserved reduction can consume only free received-backed ownership and must carry a validated sale allocation source; it cannot consume another sale's commitment. Keep it only if an actual supported caller needs it.

**Question: What happens to ownership when goods go to a lender?**

**Confirmed answer:** They remain ours but are pledged; Inventory tracks “held by lender” separately from shop stock. On return, they remain ours and become available only when release and the required physical return are complete.

**Proposed implementation:** Pledge increases pledged quantity without changing owned. Release decreases only that collateral source's pledged quantity. Forfeiture decreases owned and that same pledged quantity together. With owned 10 and pledged 10, forfeiting the matching 10 is valid even though unpledged availability is zero. Part 8 owns lender movement and confirmed settlement. Remove the unused `fromPledge=false` path during coordinated activation unless a reviewed supported caller is found; do not create an unpledged-forfeiture feature.

**Question: How are old sale/purchase returns handled in this part?**

**Proposed answer:** Preserve internal compatibility with original lot/receipt/issued pieces and exact reversible quantities. Return helpers require source evidence, not an arbitrary positive number. Public returns/refunds remain disabled; complete financial behavior remains with the later owning business module.

**Question: What transaction and lock rules will tests require?**

**Proposed answer:** Lock business source records first in the parent, then complete Inventory item-anchor sets, Ownership aggregates, lots, source commitments/receipt allocations/memberships, then downstream Inventory location/reservation/serialized custody records in stable ID order. Never acquire a business source lock after holding a later aggregate/custody lock; source qualification and cross-module prelocking must be coordinated before activation. Every Ownership append reuses the aggregate anchor; the summarizer takes that anchor but never asks for upstream source locks. Fresh/current reads follow the acquired locks. Standalone operations own atomic audit/idempotency completion; nested business operations retain their caller's transaction and replay ownership. Tests must cover existing callers' actual order, not merely assert this text.

#### OWN-02e — What values and dates do we store?

**Question: How precise are quantities and costs?**

**Proposed answer:** Mutation quantities use positive exact decimals up to three places within existing `DECIMAL(14,3)` input limits; enforce UNIQUE one and SERIALIZED whole numbers. New DTOs and JSON fields use canonical strings (`"1.000"`, `"1250.0000"`). Reuse `InventoryQuantity` for operation inputs. Cost/estimated value use non-negative exact four-decimal strings within `DECIMAL(18,4)`; excess precision rejects instead of silently rounding. Add only the narrow cost helper missing from the project; no new dependency or financial-module rewrite.

**Question: Can accumulated balances exceed one operation's quantity limit?**

**Proposed answer:** Yes. Aggregate/checkpoint balances use exact SQL `DECIMAL(24,3)` rather than floats. Do not parse a large total through the smaller Inventory input bound or an overflowing PHP scaled integer. Keep SQL arithmetic and normalized decimal-string comparison for those totals; test wider balances and overflow rejection separately. Derived owned/reserved/pledged/received-backed balances may reach zero but must not become negative.

**Question: What does a free, zero-total purchase mean?**

**Proposed answer:** Nothing is payable, so the confirmed zero-total purchase can acquire ownership at zero cost without a fake payment or account movement. Quick Buy still receives goods immediately. Negative totals, arbitrary “paid” flags, and part payment of positive totals do not qualify.

**Question: Which date becomes the acquisition date?**

**Proposed answer:** Use the business date of the settlement event that completes full payment. For zero-total Quick Buy, use its purchase date. The source service supplies this date; receipt date does not replace it later. Separately record server UTC movement time for monthly coverage, so later backdated events remain live. Do not expose historical lot edits in this part.

**Question: How do currencies and old costs behave?**

**Confirmed answer:** Each purchase lot keeps its own original cost/value/currency and history.

**Proposed implementation:** Resolve active tenant-visible currency through `TenantCurrencyService` at acquisition, uppercase using the existing currency rules, and preserve the snapshot. Do not impose a new three-letter-only rule on source currencies when the existing currency service accepts other codes. Historical reads and valid original-source reversals retain old currency/cost even if that currency is later inactive; never convert or add different currencies into one total. Sale selection requires eligible lots in the sale currency; cross-currency costing remains deferred.

#### OWN-02f — What API contracts and permissions will we test?

**Question: Which screens/API reads should be paginated?**

**Proposed answer:** Item register, acquisition lots, movement history, and receipt-link/piece candidates use `page` and `per_page` (default 25, maximum 100). Keep the existing outer success envelope. Page data is `{items,current_page,last_page,per_page,total}`, with last page at least one. Item order is name/ID ascending; lots are acquired date/ID ascending; movements are occurrence time/ID descending; receipt candidates and pieces use stable ID order. Empty results are valid pages. Summary serialization performs no lazy queries.

**Question: Which endpoint paths will we use?**

**Proposed answer:** Keep existing `/tenant/ownership/items`, `/items/{ownedItemCode}`, `/items/{ownedItemCode}/movements`, and `/inventory-options`. Add `/items/{ownedItemCode}/lots`, `/items/{ownedItemCode}/lots/{lotCode}/receipt-link-candidates`, and `POST /items/{ownedItemCode}/lots/{lotCode}/receipt-links`. Candidate query accepts `purchase_receipt_line_code` to select a receipt and return a bounded piece page for SERIALIZED goods; without it, return a receipt-candidate page. Public acquisition stays gated. Full DTO field definitions below are the test contract; do not retain incompatible old arrays as a hidden fallback.

**Question: What do commands and responses contain?**

**Proposed answer:**

- Internal acquisition command: purchase source code plus typed validated context; derive item/quantity/cost/currency/date from the source, never a public paid assertion. Other reviewed source types retain their module-owned validation.
- Public receipt-link command: `purchase_receipt_line_code`, exact `quantity`, `serialized_item_codes` (empty for nonserialized goods), and `update_key`; derive item and lot from the path/source. Require an `Idempotency-Key` for this new public mutation.
- Item summary: `code`, `inventory_item_code`, nullable `catalog_item_code`, name/description/tracking/unit fields; exact `owned_quantity`, `reserved_quantity`, `pledged_quantity`, `available_quantity`; current physical quantity, received-backed owned quantity, awaiting-receipt quantity, pending-link quantity, `lot_count`, `lifecycle_status`, `update_key`, nullable `image_url`/`image_url_expires_at`. Label these dimensions independently; do not subtract item-wide physical stock from a lot's owned total.
- Detail: summary fields plus the existing Inventory location projection and receipt-link state. Lots/pieces/history have separate pages, not unbounded embedded collections.
- Lot row: public code/source/date, original acquired quantity, current owned/reserved/pledged/available quantities, received-backed/awaiting-receipt/pending-link quantities, original unit cost/estimated value/currency/description. No internal database IDs.
- Movement row: code, lot code, operation, exact quantity and owned/reserved/pledged deltas, source module/type/code, occurrence/recording timestamps. Mutation result: item/version, affected lot/movement/link codes and newly affected serialized codes only.
- Candidate receipt row: receipt-line code, original purchase-line code, receipt date, same item code, exact received/linked/remaining-to-link quantities; piece row: serialized code, nullable identifier, receipt-line code, current location code/name, link/eligibility state. Server revalidates all candidates on submission.

**Question: What filters and states are supported?**

**Proposed answer:** Items accept search (maximum 120), tracking mode, and `holding=all|current|depleted` (default `all` to preserve access to history). Lots accept `currency_code` and the same holding filter; history accepts only pagination in this part. Receipt candidates accept search (maximum 120) and optional receipt-line code. Unknown states or out-of-range pagination reject; blank optional search means no text filter. Derive holding from current quantity, not a stale lifecycle label.

**Question: Who can read/link records, and how are stale or invalid writes handled?**

**Proposed answer:** Reads need `ownership_management`, `inventory_management`, and `list_owned_item`. Linking writes reuse `manage_owned_item`; retain the existing create permission plus inactive manual-acquisition gate for manual creation. Internal operations depend on their owning business permissions, not possession of a public bypass token. Missing/cross-tenant public resources use existing 404 `TenantNotFound`; invalid associations/quantities use localized 422 `InvalidTenantRequest`; unauthorized/gated access uses existing 403 handling. Replay/payload conflicts use existing idempotency exceptions. Add `OwnedItem.update_key` for public stale linking: increment once per logical ownership/commitment/link mutation, never for summaries. Stale version gives existing 409 `AlreadyUpdatedException` with no writes. Replay is resolved before rejecting the original request's now-old version. Frontend refreshes after conflict and asks the user to reselect; no automatic resubmission.

#### OWN-02g — How do monthly summaries avoid recalculating everything?

**Question: What gets saved each month?**

**Confirmed answer:** Closing owned, reserved, and pledged quantities by item and purchase lot, including paid goods awaiting arrival. Current balance starts from a saved month and adds newer changes. Availability is derived; physical custody remains Inventory's responsibility.

**Question: What tables and live records support that?**

**Proposed answer:** Add `ownership_monthly_checkpoints` for tenant/item/month metadata and item totals, and `ownership_monthly_checkpoint_balances` for its exact lot totals. Header includes tenant/item, local month, timezone, UTC cutoff, committed movement watermark, completion, and unique tenant/item/month. Child includes checkpoint/lot and `DECIMAL(24,3)` owned/reserved/pledged with unique checkpoint/lot. Scope children through their tenant-bound header. Retain zero/depleted lots, and require item totals to equal child totals. Receipt allocations, current source commitments, and serialized memberships remain indexed live records; do not freeze their eligibility into a summary or re-sum source history from inception on each release.

**Question: Which movements are already included?**

**Proposed answer:** Only movements at/below the watermark and recorded before the stored UTC cutoff. Tail membership is above-watermark OR on/after-cutoff OR missing recording timestamp. Normal new appends always set recording time from server UTC. Null legacy timestamps remain visible in live quantities and are never silently covered; repair/migration of historical null timestamps is outside this fresh-deployment scope. Occurrence/acquisition dates do not control checkpoint coverage.

**Question: How does publication stay consistent with new changes?**

**Proposed answer:** Every authoritative Ownership movement append locks/reuses its aggregate anchor. Summary locks the same anchor in a fresh per-item transaction and publishes header/children together. Operational authoritative reads use a fixed current header/base and locked uncovered deltas after anchors; browse pages use a coherent statement/snapshot so publication cannot mix an old base with a new tail. Receipt-only link mutations update live link state/version but append no artificial acquisition/stock movement. Summary never increments the Ownership version. Prevent an earlier repeatable-read snapshot contaminating summary; monthly tenant orchestration rejects invocation inside an existing business transaction.

**Question: What if the job is delayed or fails?**

**Proposed answer:** Use the latest older summary plus its tail; with none, calculate that item's retained history for initial publication. Stream an item-ID upper bound in chunks of 100, commit one item at a time, and insert child balances in batches of 500. Skip completed item/months; retry missing ones. Restore TenantContext after success/failure. Pin tenant-local completed month before dispatch; reject current/future/malformed months. Follow the existing scheduled queue, logging, and overlap pattern; proposed schedule is day 2 at 01:00 UTC, independent of Inventory job completion. Recovery is `ownership:summarize-monthly --tenant=... --month=YYYY-MM`, with no destructive rewrite. Explain/timing checks must demonstrate old covered history is excluded, not merely show a test passing.

#### OWN-02h — What does the user see?

**Question: How are “owned”, “received”, and “available” shown?**

**Proposed answer:** Show Owned, Reserved for sales, Pledged, Received and linked, Awaiting receipt, and Received items need linking as separate labels. Owned available excludes reservations/pledges but is not automatically physically ready for sale. Show current Inventory quantities/locations separately. Default register retains current and historical items with an explicit holdings filter. Paid/unreceived records use an awaiting-receipt state; depleted records keep history and can later gain a new acquisition lot.

**Question: How does the linking reminder work?**

**Confirmed answer:** Inform the user after goods are received when their pieces still need linking.

**Proposed implementation:** Keep an inline persistent notice and count on the affected item/lot, plus a “Link received items” action for `manage_owned_item`. Read-only users see the state without the write action. Candidate search/pagination uses the purchase receipt, not every serialized item in the tenant. Clear stale selections after filter/source changes, show exact remaining capacity, and refresh item/lot/candidates after success or 409. No new notification module is required.

**Question: Will Ownership show pictures and lender-held goods?**

**Proposed answer:** Reuse linked Inventory photos, five-minute signed URLs/expiry, full-size opening, and the bundled placeholder when missing/failed/expired. Do not copy files or add uploads. A lender-held label/location must use real Part 8 custody data; pledged ownership alone must not invent a lender location or claim the goods have physically left. Preserve that integration handoff.

**Question: What existing frontend behavior changes?**

**Proposed answer:** Fetch fresh detail rather than using a list object; guard detail/history/candidate requests against stale responses. Keep independent item/lot/history/candidate pages and a stable request key while retrying unchanged input. Rotate keys after changed/new intent or success; retain on uncertain failure. Submit exact decimals as strings, translate labels/errors in English/Myanmar, and add separate mobile/desktop classes/components only where these controls need them. Keep manual acquisition/direct routes gated. Extend the existing layout without unrelated redesign.

**Review/next dependency:** OWN-02a–h answers are accepted together. OWN-03's matrix remains provisional by user instruction; proceed to OWN-04 and review each test's expectations during Stage 2. The user can amend behavior there, with changes recorded against the affected requirement. Acceptance is for this design, not implementation or verification. Static architecture/contract inspection only; no code, tests, schema, or runtime operations executed.

### OWN-03 — Map requirements to tests

**Depends on:** OWN-02 decisions accepted.

**Files:** Planned test files under `tests/Unit/` and `tests/Feature/TenantModule/`; this checklist.

**Current review status:** Deferred by the user because the Markdown tables are difficult to read. No matrix acceptance or scope freeze is claimed. The following prepared material remains available as a reference; review and revise individual cases during test writing instead. This deferral does not remove tests or waive database/security/concurrency requirements.

- [ ] **OWN-03a:** Give each OWN-05–11 scenario a requirement reference, expected response/balance, and expected no-write assertions on failure.
- [ ] **OWN-03b:** Identify pure unit tests, real database integration tests, HTTP/middleware tests, and independent-process concurrency tests. Do not replace one category with another.
- [ ] **OWN-03c:** Record which tests need the new schema and how the separately prepared test database will satisfy it after reviewed migrations exist. Do not make tests create schema themselves.
- [ ] **OWN-03d:** Reconcile this provisional matrix with the individually reviewed tests during Stage 2. Full-table approval is deferred; record user-approved expectation changes before corresponding implementation.

#### OWN-03a — Requirement-to-test acceptance matrix

**Prepared:** 2026-10-20. All OWN-03 work was prepared together. Matrix review is deferred; its scenario details are provisional until individually reviewed during test writing. No executable tests have been written or run. Each row maps an existing Stage 2 child ID to a requirement and proposed observable result. Split a row into small test-method review units when writing it; do not submit an entire suite as one review unit.

**Question: What does a test prove?**

**Answer:** It proves an agreed business result at the boundary it actually exercises. A parser test cannot prove a database constraint; a service test cannot prove HTTP middleware; a source-context fixture cannot prove a future loan workflow. Test actual balances/source links and failure effects, not private helper call order or code structure.

**Question: What are the common fixture values?**

**Answer:** Unless a row states otherwise, use one tenant/item/lot acquired for `10.000`, unit cost `12.3400`, total fully settled `123.40`, and one active visible currency. Use a second tenant and different source/lot for rejection cases. `B=(owned,reserved,pledged)` is shorthand; each component is asserted as an exact three-decimal string. `stock` means real Inventory custody, not inferred Ownership. Each scenario starts from its own fixture, not the previous row's state. Unit-cost snapshots and all other currency balances remain unchanged by quantity-only operations.

**Question: What must a rejected operation leave unchanged?**

**Answer:** Capture applicable before/after row counts, exact balances, immutable lot fields, remaining receipt/source allocations, current/historical piece memberships, commitments, Ownership/Inventory versions, physical movement counts, financial/account/audit records, and completed idempotency results. An invalid operation must commit no business effects. Existing failure diagnostics may follow the reviewed wrapper policy, but must not leave a success/completed replay or stranded processing record. For source/tenant failures, protect both the acting tenant and the referenced tenant. Reads must never create business rows.

##### Unit tests — proposed files under `tests/Unit/`

| Test-writing unit | Requirement | Input / expected result and failure assertions |
|---|---|---|
| OWN-05a | OWN-02e exact operation quantities | Accept `0.001`, exact maximum, UNIQUE `1`, SERIALIZED whole quantities. Reject zero/negative/non-finite/malformed/over-precision/overflow and fractional UNIQUE/SERIALIZED input. Add exact larger-balance comparison cases beyond the input parser bound; zero is valid for a derived balance. Pure tests use no database. |
| OWN-05b | OWN-02e costs | Normalize `12.34` to `12.3400`, zero to `0.0000`, optional estimated value to null, and accept maximum supported four-decimal cost. Reject negative/over-precision/overflow without rounding or float conversion. |
| OWN-05c | OWN-02a–c trusted inputs | Normalize source/item/code inputs; allow no serial numbers at acquisition; reject duplicate selected codes at linking. Public factories cannot hydrate paid status, tenant authority, or an internal bypass. Test strings such as identifier `"0"` without truthiness loss. |
| OWN-05d | OWN-02f contracts | Assert canonical strings/public codes, reserved fields, affected-only results, and flat pagination metadata. Page 1/size 25 default; size 100 accepted; page 0/size 101 reject. No internal IDs, raw paths, unbounded lots/pieces, or lazy database work in serialization. |
| OWN-05e | OWN-02d transition invariants | From B=(10,0,0), reserve 4 gives (10,4,0); release 1 gives (10,3,0); consume 2 from that source gives (8,1,0). Separate fixture: pledge 4 gives (10,0,4), release 1 gives (10,0,3), forfeit 2 gives (8,0,1). Test each transition separately and reject excess/negative results. Do not force a new calculation helper solely to mirror it in tests. |

##### Acquisition — `OwnershipAcquisitionTest`

| Test-writing unit | Requirement | Setup / expected result and failure assertions |
|---|---|---|
| OWN-06a | OWN-02a/b/e paid before receipt | Real confirmed purchase/source is settled `123.40`; acquisition creates one lot and B=(10,0,0), source `PurchaseOrderLine`, effective settlement date, cost `12.3400`. Stock remains zero; no Inventory movement/piece or additional cash/accounting effect is created by Ownership. Different same-name goods retain distinct item identity; explicit valid item reuse stays stable. |
| OWN-06b | OWN-02a/e source eligibility | Unpaid, part-paid, cancelled/draft, missing, other-tenant, and wrong-line/item sources reject with no effects. A confirmed zero-total source acquires at zero cost with no fake payment/account movement. An arbitrary request paid flag cannot qualify a positive unpaid purchase. Assert missing/cross-tenant public lookup is 404; invalid accessible association/settlement is 422. Quick Buy tender validation itself remains Part 4 acceptance. |
| OWN-06c | OWN-02a/e immutable lots | Two purchases produce separate lots with their own quantity/date/currency/cost. Later reserve/sale/link does not alter originals or combine currency totals. New acquisition with inactive/unavailable currency rejects; historical reads retain the saved currency. |
| OWN-06d | OWN-02a replay/source uniqueness | Same completed key/payload returns original affected lot and no new rows. Same key/changed payload gives existing 409 conflict. Different key/same acquired source rejects duplicate source (422), with one lot/movement still present. A new request result must identify its new lot, not the first old lot. |
| OWN-06e | OWN-02b/c UNIQUE/reacquisition | A second current UNIQUE acquisition for the same item rejects. After valid complete disposal, a different valid purchase source creates a new lot under the preserved aggregate, without deleting old source/lot history or creating duplicate active piece ownership. |

##### Receipt and piece linking — `OwnershipReceiptLinkTest`

| Test-writing unit | Requirement | Setup / expected result and failure assertions |
|---|---|---|
| OWN-07a | OWN-02c separate receipt/link | Acquire 10 with zero stock, then receive/link 4: B stays (10,0,0), stock becomes 4, received-backed owned quantity 4, awaiting receipt 6, original lot date/cost unchanged, and still exactly one acquisition movement. Link the remaining 6 later without staged UI. Trusted immediate receipt can auto-link; an internal auto-link failure rolls back its enclosing receipt transaction. |
| OWN-07b | OWN-02c allocation capacity | For an already received 4, link 1 then 3; remaining-to-link goes 3 then 0. Reject an extra 0.001, duplicate allocation, different purchase/lot/item/tenant. Linkable capacity is still-unlinked receipt quantity, not outstanding unreceived order quantity. Original purchase receipts linked across the lot cannot exceed acquired quantity. |
| OWN-07c | OWN-02c unknown pieces/reminder | Acquire 3 SERIALIZED goods without piece records; receive actual A/B/C; pending-link count is 3 until linked. Link A/B then C, with exact count-to-quantity equality. End state has three current lot memberships and no duplicate acquisition. Pending-link state/read data supports a reminder; browser rendering is separately checked. |
| OWN-07d | OWN-02c exact membership | Duplicate A/A, foreign item/tenant, not-received, wrong-receipt, wrong-count, and piece already currently owned elsewhere all reject without changed links/memberships/versions. Exercise the actual single-current-membership MySQL constraint as well as service validation. |
| OWN-07e | OWN-02c/d history/return | Sell A: original membership closes and received-backed owned quantity falls. A legitimate original-source return restores its original lot with a new membership episode; a later valid new purchase after disposal uses a new lot. Keep history and enforce one current membership. Double/excess/wrong-source return rejects; no fake new purchase receipt. |
| OWN-07f | OWN-02c/d ownership plus receipt | Owned/unreceived, received/unowned, and received/unlinked goods each reject sale/pledge eligibility. A received lot A cannot supply paid-but-unreceived lot B even for the same item. QUANTITY selection is fungible only within the same item/unit and receipt-backed lot capacity; SERIALIZED selection must use the exact lot's pieces. |

##### Sale/pledge primitives — `OwnershipQuantityOperationTest`

| Test-writing unit | Requirement | Setup / expected result and failure assertions |
|---|---|---|
| OWN-08a | OWN-02d sale reserve | Received/linked B=(10,0,0): reserve source S1 quantity 4 → (10,4,0), source remaining 4, available 6, physical stock unchanged. Further reserve 6 succeeds; reserve `6.001` against the initial remaining 6 rejects with no effects. |
| OWN-08b | OWN-02d same-source release | S1 reserves 4 and S2 reserves 3. Release S1 quantity 1 → S1 remaining 3 and total reserved 6. Excess S1 release cannot use S2's balance. After full S1 release, another release rejects even with a new key. No acquisition/physical/cash effect. |
| OWN-08c | OWN-02d reserved consumption | Received B=(10,4,0), S1 remaining 4: consume 2 → (8,2,0), S1 remaining 2 and matching receipt allocation reduced 2. Consume final 2 → (6,0,0), S1 consumed. Exact piece memberships close. Inventory issue and Ownership must roll back together in the controlled integration transaction. |
| OWN-08d | OWN-02d unreserved boundary | Immediate unreserved reduction can use only free received-backed quantity and a valid trusted sale allocation. It must reject reserved/pledged/unreceived/unlinked goods. Retain positive support only if an actual supported caller needs it; otherwise test that callers use reserved consumption and do not invent a new public bypass. |
| OWN-08e | OWN-02d pledge | Received/linked B=(10,0,0): pledge L1 quantity 4 → (10,0,4), source remaining 4, stock unchanged by this Ownership helper. Reserved/unreceived/unlinked/wrong-piece quantities reject. No lender movement, loan receipt, or cash/accounting row is created by the primitive. |
| OWN-08f | OWN-02d pledge release | L1 pledges 4 and L2 pledges 3. Release 1 from L1 → total pledged 6 with L1 remaining 3. Excess/repeated/wrong-source release rejects. Owned stays 10; no new lot/receipt/cash. A helper release alone must not claim physical return from lender. |
| OWN-08g | OWN-02d pledged forfeiture | Received B=(10,0,10), L1 pledges all 10: forfeit matching 10 → (0,0,0), close memberships and source allocations as applicable, retain original cost/history. Reject excess or another loan's source/pieces. Do not require positive unpledged availability and do not create a Sale/cash receipt. |
| OWN-08h | OWN-02d return compatibility | Valid original sale return restores only its lot/issued pieces/received allocation; valid purchase return reduces only its source lot/free linked quantity. Wrong source or excess rejects and leaves both Ownership and Inventory unchanged. Inactive historical currency retains original snapshot without revaluation. Complete public return/refund workflows are not exercised. |

##### HTTP/read contracts — `OwnershipEndpointTest`

| Test-writing unit | Requirement | Setup / expected result and failure assertions |
|---|---|---|
| OWN-09a | OWN-02f/h gates | With authenticated tenant users and real middleware, test each missing general feature or permission. Reads require list permission; public manual acquisition additionally needs its inactive manual gate. Disabled/unpermitted requests give existing 403 behavior with no effects. Normal 2.0 defaults must not expose manual acquisition. |
| OWN-09b | OWN-02f linking authority | List-only user can read candidates but write gives 403; manage permission plus features can link. Missing/foreign path resources give 404; accessible wrong association gives 422. Reject or ignore undeclared privileged input without granting authority. Paid flags/tenant IDs cannot bypass source resolution. |
| OWN-09c | OWN-02f pagination/filter | Assert defaults 25/max 100, stable name/ID items and date/ID lots, empty page metadata, correct search/tracking/holding/currency filters, and item access beyond previous caps. Current/depleted filters reflect quantity; default all includes awaiting receipt and history. Invalid filters/page bounds give 422. |
| OWN-09d | OWN-02f history/candidates | Assert newest occurrence/ID history ordering and source/reserved delta/recording timestamp fields. Candidate page returns receipts by default and exact pieces for selected receipt. No full tenant piece list; filters and public codes match the accepted contract. |
| OWN-09e | OWN-02f/g bounded reads | Compare equivalent page requests with 1 versus 25 rows/lots, keeping auth/feature/query bootstrap constant. No one-query-per-row resource pattern; no unbounded embedded collections. Functional counts do not substitute for later MySQL EXPLAIN measurements. |
| OWN-09f | OWN-02h images/privacy | Reuse real private-file signing/test filesystem: photo URL and expiry present for an accessible linked photo; missing file gives null; another tenant/raw path is never exposed. Placeholder/expired-image browser display is a manual acceptance case, not a PHP test claim. |
| OWN-09g | OWN-02f version/replay | With aggregate version v, valid linking advances to v+1 once; stale/new-key request gives 409 `UPDATING_ALREADY_UPDATED_DATA` and no writes. Same original key/payload replays original success despite its old version, with `Idempotent-Replay: true`. Missing required replay key rejects (422). |

##### Transactions and concurrency — `OwnershipTransactionTest` / `OwnershipConcurrencyTest`

| Test-writing unit | Requirement | Setup / expected result and failure assertions |
|---|---|---|
| OWN-10a | OWN-02d outer atomicity | Begin controlled outer transaction, acquire/receive/link or issue/consume, then throw. All operation-local Ownership/Inventory/link/audit/completion writes and versions return to their prior state. Seed source/payment fixtures before the tested transaction; do not pretend this proves full Quick Buy accounting. |
| OWN-10b | OWN-02a/d replay/failure | Inject failures after a movement/link write and at audited public completion. Assert no partial success, safe retry produces one result, public replay uses HTTP shape, and internal replay returns data so later parent work can complete. Separate each failure boundary for review. |
| OWN-10c | OWN-02a/f key scope | Same key/changed payload gives 409 `IDEMPOTENCY_KEY_CONFLICT`; in-progress matching key uses existing 409 processing response. Simulated lost response then replay creates no extra rows. Same textual key in another tenant/operation does not replay someone else's data. |
| OWN-10d | OWN-02a/c uniqueness races | Two real processes acquire one paid source, then separately contend to link one piece. Observe a real lock wait; one source acquisition/current membership survives. Contender rejects or replays only as its source/key contract permits. Do not pass solely because one process happened to run first. |
| OWN-10e | OWN-02d competing operations | Compete for the last received-backed lot quantity and, separately, one serialized piece. Test sale versus sale and sale versus pledge. Exactly one valid allocation succeeds; final owned/reserved/pledged/source/piece balances reconcile and none are negative. |
| OWN-10f | OWN-02d lock order | Supply opposite item/lot input order to two processes using the reviewed parent prelock protocol. Bound execution, verify contention/completion/final balances, and detect worker/deadlock failures. This is a primitive ordering test, not full cross-module workflow certification. |
| OWN-10g | OWN-02c/d receipt race | Hold receipt/link work before commit while another operation checks eligibility. After commit the contender rechecks current membership/custody; after rollback it rejects unlinked/unreceived quantity. Use separate review cases for each result; no stale pre-lock authorization. |

##### Monthly summaries — `OwnershipMonthlySummaryTest` plus independent workers

| Test-writing unit | Requirement | Setup / expected result and failure assertions |
|---|---|---|
| OWN-11a | OWN-02g first base | Seed source movements across lots, paid/unreceived holdings, reserved/pledged balances, and exhausted lots. First header/item/lot totals exactly equal an independent full SQL ledger sum; zero lots persist. No stock/ownership movement, entitlement/version, commitment, or membership change. |
| OWN-11b | OWN-02g incremental/idempotent | Publish month M, add eligible month M+1 changes, publish next month. Verify earlier checkpoint is the base plus only uncovered qualifying rows. Repeat each month returns zero new publications and unchanged totals/versions. |
| OWN-11c | OWN-02d/g live changes | After checkpoint, independently test acquisition/reserve/release/consume/pledge/forfeit deltas and compare all balances with full source totals. Concrete case: base (100,10,20), acquire 5 and consume 3 reserved → (102,7,20), available 75. Receipt-only linking changes live link state/version, not closing entitlement or physical stock. |
| OWN-11d | OWN-02g coverage predicate | Seed a new-month row with ID below captured watermark, later-ID row, and later-recorded backdated business event. Each stays in live quantities exactly once. Null recording-time fixture remains visible rather than silently covered. Use controlled fixture timestamps; normal appends must overwrite caller recording timestamps with server time. |
| OWN-11e | OWN-02g fallback | No checkpoint uses source history; missing last month uses latest older base plus longer tail. After publication, operational reads must not keep taking the no-checkpoint path or rescan covered history. |
| OWN-11f | OWN-02g dates/context | Assert tenant-local month/end boundary converted to UTC, frozen existing cutoff after timezone change, and strict invalid/current/future rejection without publication. Original TenantContext is restored after success and injected failure; tenant summary rejects an enclosing business transaction. |
| OWN-11g | OWN-02c/d/g live authority | After a checkpoint, another source's reserve/pledge still cannot be released, pending receipt links still block eligibility, and current exact-piece changes remain authoritative. Monthly totals must not supply fabricated source membership or lender custody. |
| OWN-11h | OWN-02g append races | Separate two-process cases for append commits before summary anchor acquisition and append waits until after publication. In both, header plus tail equals full current ledger with no omitted/double-counted quantity and unchanged source records. |
| OWN-11i | OWN-02g atomic publication | Race two summaries; one item/month publication survives. Inject failure while publishing the second item: first stays complete, failed item has no partial header/children, rerun skips first and completes missing item. Cleanup committed fixtures with FK checks enabled. |
| OWN-11j | OWN-02g schedule/recovery | Tenant job invokes real summary; root dispatch pins month before queue delay (dispatch can be faked); schedule is day 2/01:00 UTC. Verify queue/logging/overlap configuration, invalid recovery arguments/no writes, valid recovery skip/resume, and tenant isolation. Faked dispatch is not deployed worker evidence. |
| OWN-11k | OWN-02g incremental queries | Observe query predicates and fixture-based uncovered-row results: covered old rows excluded, selected checkpoint fixed, and batching independent of item/lot row count. Do not hard-code one optimizer plan or claim measured performance; substantial-history EXPLAIN/timing is OWN-33. |

#### OWN-03b — Test categories and integration boundaries

**Question: Where will each kind of test run?**

**Answer:**

| Category | Planned coverage | Allowed test substitutes / limits |
|---|---|---|
| Pure unit | OWN-05 in `OwnershipQuantityTest` / `OwnershipContractTest` | No database; test actual DTO/value behavior. Do not duplicate Inventory arithmetic tests unless asserting Ownership's boundary or wider totals. |
| Real MySQL integration | OWN-06/07/08 and OWN-10a–c | Reuse explicit test connection, real Ownership repository/services and Inventory custody. Source fixtures persist actual purchase/order/payment/receipt and sale/allocation records without executing whole business workflows. Roll back ordinary fixtures. |
| Real HTTP/middleware | OWN-09 | Authenticate tenant actors with persisted permissions/features. Do not disable the very middleware being tested or substitute mocked authorization for gate evidence. |
| Independent-process MySQL | OWN-10d–g, OWN-11h/i | Committed isolated tenant fixtures, separate connections, observed lock waits, bounded processes; cleanup own records only with FK enforcement. SQLite/same-connection transactions cannot replace these cases. |
| Summary service/job integration | OWN-11 | Real repository/service publication outside an enclosing fixture transaction. Fake only root queue dispatch where explicitly stated. Test summary commits/failures with committed isolated fixtures. |
| Browser/manual/deployment | OWN-32g/h and OWN-33 | Record pending until actually observed. PHP data tests do not prove UI fallback, mobile interaction, deployed queue consumption, or large-history speed. |

**Question: How do we test loan primitives before Part 8 exists?**

**Answer:** Use explicit trusted-caller source context for the Ownership primitive and persist its real source-bound Ownership commitment/lot/piece data. A minimal real loan fixture may identify the parent loan, but do not invent a fake production collateral table or add the Part 8 module just to make a test fixture. If the future collateral-source resolver needs a test boundary adapter, label it explicitly; never mock Ownership balances, membership constraints, or Inventory custody. Such tests prove same-source primitive safety, not actual lender handover, maturity, authorized collateral creation, or non-cash loan settlement. Those remain Part 8 acceptance. Resolve missing future dependencies in fixture/adapters, not by weakening source-bound assertions.

**Question: Does this test the entire Quick Buy / Quick Sell?**

**Answer:** No. Part 2 tests acquisition qualification using real existing purchase/payment records and real Ownership/Inventory effects. Narrow caller compatibility is checked during activation, but full payment/financial rollback/replay across Purchasing/Sales is deferred to Parts 4/5. In particular, an internal trusted auto-link rollback case is not a replacement for a full Quick Buy transaction test. Preserve those handoffs explicitly.

#### OWN-03c — Schema and execution prerequisites

**Question: Can database tests be written before their schema exists?**

**Answer:** Yes. Write the agreed scenarios and assertions first. Database execution waits for the separately prepared schema; missing schema is an environment/schema blocker, not an assertion success or acceptable skip. Do not add `RefreshDatabase`, `DatabaseMigrations`, `Schema::create`, automatic migrations, temporary substitute production tables, or a SQLite fallback to make execution appear ready.

| Prerequisite group | Required capabilities | Tests depending on it |
|---|---|---|
| Existing source/auth schema | Tenant/actors/features/permissions; active visible currencies; purchase orders/lines/payment/refund/receipt records; minimal sale/allocation source records | Source acquisition and HTTP cases; only the specific fixture parents needed by each test |
| Inventory schema | Item identity/version, locations, movements, serialized pieces, reservations, existing checkpoint support | Receipt/link/eligibility and physical rollback/concurrency; reuse accepted Part 1 test support |
| Ownership baseline | Aggregates/lots/movements and owned/reserved/pledged deltas with preserved source uniqueness | Existing-path assertions and new acquisition/quantity operations |
| New receipt/piece/source schema | `ownership_receipt_links` remaining-owned quantity, serialized membership history/single-current constraint, current source commitments and piece commitments, aggregate `update_key` | OWN-06/07/08/09g/10 scenarios relying on these fields/constraints; exact migration object names finalized during their OWN-15 review without changing behavior |
| Ownership monthly schema | Header/item totals, lot balances, exact wider decimals, unique month/lot keys and indexed recording-time/watermark queries | OWN-11 and checkpoint-backed operational/read/concurrency cases |
| Test observation permissions | Independent MySQL connections and sufficient read access to lock-wait metadata using the existing worker approach | Independent-process evidence only; report unavailable observation as a blocker rather than assuming contention occurred |

**Question: How do we cross from tests-first to a prepared test schema?**

**Answer:** OWN-04 reviews/reuses connection and read-only preflight support. Initial preflight can report that future OWN-15 tables do not yet exist; write/review all tests anyway. OWN-12 classifies existing assertion failures, missing application classes/routes, and schema blockers separately. After test review, gates/helpers and reviewed migration files are written in the required sequence. The user/environment separately prepares the disposable schema; the agent does not execute migrations. Read-only preflight must then verify actual required tables/columns/constraints before authorized MySQL execution. Do not claim a red-to-green database cycle where only missing-table errors were ever observed; record actual behavior assertions once prerequisites exist.

**Question: Which local setup will be reused?**

**Answer:** Reuse the explicit `.env.test` / `.env.testing` disposable MySQL selection from `InventoryMySqlTestCase`; retain process-local cache/throttle isolation. Extend or add a narrow Ownership preflight only when OWN-04 is implemented, using metadata reads without printing credentials. Plain tests use rollback fixtures. Summary/concurrency fixtures stop workers, remove only their own source children/Ownership children/Inventory children/tenant parents in FK order, restore clocks/context, and confirm no committed verification tenants remain. Do not refactor shared harness code unless a concrete Ownership need requires it.

#### OWN-03d — Per-test review and evidence rules

**Question: Must the user accept the whole table before test writing starts?**

**Answer:** No. The user explicitly deferred this review. The 49 scenario groups are a planning reference, not a frozen accepted test contract. Proceed to OWN-04, then write/review each test in a small unit with a plain-language setup/action/expected-success/expected-failure explanation. The user can amend that expectation during the test-writing review. Preserve agreed business rules unless the user changes them; do not drop coverage merely because current code fails. Technical test scaffolding does not authorize production behavior changes before Stage 3.

**Question: What if a rule needs to change later?**

**Answer:** Record a Q&A amendment with its reason and affected requirement/scenario IDs, obtain user review, update/write the test first, then change implementation. Do not alter an accepted business expectation to accommodate a bug. Unsupported unreserved-sale paths are eliminated or routed through supported consumption rather than invented to satisfy a positive test.

**Question: What results get recorded?**

**Answer:** Per child record: test written/reviewed, execution status, exact command/environment category, pass/fail counts when actually run, observed effects/no-effects, failures versus harness/schema blockers, and accepted deferrals. No test-count prediction is a completed result. At OWN-12, report baseline coverage honestly; at OWN-32, verify the connected implementation; at OWN-33, measure query plans/performance. Future deployment/browser scenarios remain pending until observed.

**Status:** OWN-03 review is deferred by the user; checkboxes remain unchecked and do not block OWN-04a. Review case expectations individually in Stage 2 and reconcile the reference as decisions are made. Tests-first remains intact. Documentation edits/checks only; no source/schema/test execution.

## Stage 2 — Test writing first

**Stage gate:** No production changes for gates, helpers, schemas, orchestration, controllers, or frontend until OWN-04–12 test writing/review is accepted. OWN-03 full-table acceptance is not required. Review each test's setup and expected behavior in simple Q&A prose during its writing unit, and record amendments before implementation. Write tests against agreed interfaces even if implementation is absent. Existing satisfied behaviors may already pass; do not force artificial failures.

Suggested test files are `OwnershipQuantityTest`, `OwnershipContractTest`, `OwnershipAcquisitionTest`, `OwnershipReceiptLinkTest`, `OwnershipQuantityOperationTest`, `OwnershipEndpointTest`, `OwnershipTransactionTest`, `OwnershipConcurrencyTest`, and `OwnershipMonthlySummaryTest`. These names are planning targets, not a requirement to create a duplicate file where suitable coverage already exists.

### OWN-04 — Test infrastructure

**Depends on:** Accepted OWN-02 design. OWN-03 matrix review is deferred by user instruction and does not block test infrastructure.

**Files:** `tests/Support/InventoryMySqlTestCase.php`, existing fixture/preflight/worker support; new Ownership support only where needed.

- [x] **OWN-04a:** Review and reuse explicit disposable-MySQL selection and read-only schema preflight. Missing schema must be reported separately; never run migrations or fall back to SQLite for locking tests.
- [x] **OWN-04b:** Add the smallest rollback fixture for tenant, actor, currency, Inventory identity/custody, and Ownership acquisition sources. Persist real source records; avoid full Purchasing/Sales workflows.
- [x] **OWN-04c:** Add committed Ownership fixture cleanup for concurrency tests in foreign-key dependency order. Reuse existing process coordination rather than starting services.
- [x] **OWN-04d:** Add bounded worker actions and lock-wait observation for Ownership races. Never put secrets in worker arguments/output.

#### OWN-04a — Disposable MySQL connection and read-only preflight

**Implementation:** `tests/Support/ownership_database_preflight.php` added. **Review:** Accepted by the user through “done” on 2026-10-20; OWN-04a is checked. No other OWN-04 child is implemented by this unit. Future schema readiness remains pending OWN-15; acceptance of the preflight does not claim that schema exists.

**Question: What was reused?**

**Answer:** The new script requires the existing `inventory_database_preflight.php`, retaining its explicit `.env.test` / `.env.testing` selection, disposable MySQL-name guard, validated PDO connection, and Inventory baseline checks. Existing `InventoryMySqlTestCase` already supplies that same test-environment selection, rollback fixtures, and isolated cache/throttling; it is unchanged. No duplicate connection loader or production bootstrap was introduced.

**Question: What does the Ownership check inspect?**

**Answer:** Metadata only: existing aggregate/lot/movement columns, exact decimal types, InnoDB engines, and tenant/code, tenant/item, source-acquisition, and movement/idempotency unique keys. It checks unique-key columns rather than depending on an index's name. It also reports the known planned receipt-link/monthly tables and aggregate `update_key` separately from baseline failures. Source/membership/commitment and monthly definitions must be added to the readiness manifest after their OWN-15 schema review; table existence alone cannot certify those constraints.

**Question: What happened when it ran?**

**Answer:** `php -l tests/Support/ownership_database_preflight.php` passed. The read-only command `php tests/Support/ownership_database_preflight.php` connected through `.env.testing` to MySQL **8.0.40** and reported:

- Inventory prerequisite tables/versions: passed.
- Existing Ownership baseline fields, decimal types, InnoDB engines, and required unique keys: passed.
- Planned tables absent: `ownership_receipt_links`, `ownership_monthly_checkpoints`, `ownership_monthly_checkpoint_balances`.
- Ownership aggregate `update_key`: pending schema.
- Full Part 2 readiness: pending the new OWN-15 schema and constraint review. The script intentionally returns a nonzero pending/setup status, not a feature-test failure or full-readiness success.

**Question: Does pending schema block writing tests?**

**Answer:** No. Continue writing/reviewing the tests and fixtures in the required sequence. Do not execute database scenarios that need missing structures or treat them as passing skips. After test review and later migration-file review, the user/environment separately prepares the schema; the agent does not run migrations. Extend the preflight with the reviewed new constraints and rerun it before the affected database tests.

**Verification and limits:** Metadata reads and PHP syntax only; Laravel was not booted by the script. No application data/fixtures were written, no PHPUnit tests run, no migrations/schema changes performed, and no services/workers started. PDO diagnostics expose SQLSTATE only, not connection credentials. Documentation/diff whitespace checks passed. Next child after acceptance: OWN-04b rollback fixture.

#### OWN-04b–d — Fixture and worker implementation batch

**Authorization:** The user requested “work all 04”, authorizing the remaining OWN-04 children together, then accepted them through “mark done”. OWN-04a–d implementation/review is complete and all four checkboxes are checked. Fixture/worker runtime verification remains pending; acceptance does not claim those checks passed.

**Question: What does the rollback fixture create?**

**Answer:** `OwnershipMySqlTestCase` extends the existing guarded `InventoryMySqlTestCase`, reusing its disposable MySQL connection, rollback transaction, and cache/throttle isolation. `OwnershipFixture` creates a uniquely identified tenant, actor/role, tenant-owned currency/account/unit/location, and explicitly switches actor plus TenantContext when activating a fixture. Default roles have no business permissions and no features are enabled; HTTP tests choose those separately. Clocks are frozen for reproducible source dates and reset during teardown.

**Question: Can tests represent paid goods that have not arrived?**

**Answer:** Yes. `createOwnershipInventoryItem` creates only zero-stock metadata. `createOwnershipPurchase` persists real supplier/order/line/payment source rows without calling PurchasingService or Ownership acquisition. Quantity, unit price, payment amount, and status are explicit string inputs; tests can supply full/partial/no payment. Null or zero payment creates no payment row. The helper does not calculate full payment for custom quantities or post financial entries: the test must supply the intended source facts.

**Question: How is receipt different from an acquisition fixture?**

**Answer:** `receiveOwnershipPurchase` separately persists a real receipt source and calls the real Inventory receipt service, retaining its actual serialized codes. It does not acquire or link Ownership. `seedOwnershipLot` supplies a directly seeded aggregate/lot/acquisition-movement baseline when testing another operation in isolation; it is never used to assert that production acquisition works. Tests of acquisition must invoke the production operation themselves. Fixture helpers require their active tenant's matching purchase/item references. New receipt-link/membership/commitment/checkpoint schema is not fabricated here.

**Question: What happens in a normal test failure?**

**Answer:** The inherited test transaction rolls back ordinary fixture writes and TenantContext clears. Ownership teardown forgets its actor and resets both mutable and immutable Carbon clocks in a `finally` boundary. Parent fixture IDs are tracked immediately so partial committed setup can be cleaned by the committed variant. These behaviors are implemented but have not been executed in this unit.

**Question: How do independent processes see the same records?**

**Answer:** `CommittedOwnershipFixture` disables the enclosing fixture transaction; the future test then uses explicit transactions for the operation under inspection. Workers are registered and stopped before teardown. Cleanup first validates every recorded tenant ID/code and its unique Ownership Test marker, then deletes only records whose `tenant_id` belongs to those exact fixtures. It discovers actual tenant-owned tables from metadata and retries only FK-blocked parents after children; it never disables FK checks or creates/drops tables. All fixture tenants are cleaned together so intentionally cross-tenant negative-test source links can be removed in dependency order. It removes its tracked global roles/owners last and asserts its tenant/owner IDs are gone. An unresolved FK blocker raises a visible error rather than deleting unrelated data.

**Question: What worker support is available?**

**Answer:** `OwnershipWorkerProcess` starts bounded separate PHP processes with allowlisted nonsecret reference payloads, polls buffered JSON states, observes actual MySQL waits through `performance_schema.data_lock_waits`, and releases held transactions through stdin. Each process has a 20-second parent timeout; state/lock observation and holder-release waits have shorter deadlines. Raw SQL/error output and credentials are not returned as worker diagnostics.

**Question: Which actions does the Ownership worker implement now?**

**Answer:** The initial action whitelist is `lock`, `reserve_sale`, `pledge`, and `summary`. Locking covers supplied purchase sources, complete Inventory item anchors (including those resolved from Ownership references), Ownership aggregates, and lots in ID order. Immutable item discovery happens before the transaction so it does not establish a stale repeatable-read snapshot. Existing reserve/pledge actions call real current helpers, not substitutes for their missing safeguards. Summary targets the planned real `OwnershipMonthlySummaryService`; until implemented it rejects rather than reporting fake success. Case-specific acquisition/link/consumption actions and any changed typed service signatures are added alongside their later tests, not guessed or implemented as production behavior in this harness step.

**Question: What existing support changed?**

**Answer:** Extracted the existing Inventory worker's connection/cache/request/clock bootstrap unchanged into `inventory_worker_bootstrap.php`, required by both workers. The existing Inventory worker's operation body is unchanged. This is a narrow test-support reuse needed by Ownership; no production/shared business service was refactored. Static comparison confirmed the extracted bootstrap and retained operation body match the prior worker source.

**Verification:** All seven changed/new support files passed PHP syntax checks; diff whitespace checks passed. No PHPUnit, fixture provisioning/cleanup, concurrency process, schema change, migration, scheduler, or application server was run. Windows stdin coordination, real cleanup order, and real lock observations remain runtime checks for the subsequently authorized tests; syntax alone does not prove them. No committed fixture tenant was created by this unit.

**Next dependency after acceptance:** OWN-05a, beginning with a small plain-language Q&A for its quantity test expectations. OWN-03 remains deferred/provisional; no full-table approval is required.

### OWN-05 — Pure unit tests

**Depends on:** Accepted OWN-02 design and per-test expectation review; OWN-04 only where framework bootstrap is required. OWN-03 remains a provisional reference.

- [ ] **OWN-05a:** Test exact quantity zero/positive distinction, `0.001`, precision, overflow, invalid values, UNIQUE one, and SERIALIZED whole quantities. Reuse existing Inventory tests where they already prove shared arithmetic; test Ownership's use of that contract.
- [ ] **OWN-05b:** Test exact cost/value normalization, bounds, zero, negative/over-precision rejection, and no float conversion in new Ownership DTOs.
- [ ] **OWN-05c:** Test command normalization, optional unknown serials before receipt, duplicate serial selection, and separate trusted source context versus public input.
- [ ] **OWN-05d:** Test typed result/read serialization, pagination boundaries, public codes, reserved fields, and no lazy model/database work during serialization.
- [ ] **OWN-05e:** Test transition calculations: reserve, release, sale consumption, pledge, pledge release, and forfeiture. Use separately reviewable cases for each operation.

### OWN-06 — Acquisition integration tests

**Depends on:** OWN-04/05; real MySQL schema preparation before execution.

- [ ] **OWN-06a:** Test fully paid acquisition before any receipt: owned quantity/cost exists, physical stock stays zero, and no Inventory movement is created.
- [ ] **OWN-06b:** Test unpaid/part-paid source rejection, cross-tenant/missing/mismatched source rejection, and zero-total purchase semantics agreed in OWN-02e.
- [ ] **OWN-06c:** Test multiple immutable lots with separate costs/currencies; original snapshots survive later quantity changes.
- [ ] **OWN-06d:** Test duplicate acquisition source, replay, and conflicting request data without duplicate lots/movements. Separate replay assertions from source-conflict assertions.
- [ ] **OWN-06e:** Test UNIQUE current-ownership duplication rejection and valid new-lot acquisition after the earlier ownership is exhausted.

### OWN-07 — Receipt and serialized-piece linking integration tests

**Depends on:** OWN-06 fixture/contracts.

- [ ] **OWN-07a:** Test receipt links to an existing acquisition without another acquisition movement or cost change; ownership and received totals remain separate.
- [ ] **OWN-07b:** Test receipt-link quantity limits, distinct partial links, excess/duplicate linking, and wrong purchase/lot/item/tenant rejection.
- [ ] **OWN-07c:** Test paid serialized acquisition with unknown pieces, receipt of actual pieces, pending-link reporting, and successful exact lot membership.
- [ ] **OWN-07d:** Test duplicate/wrong-item/wrong-tenant/not-received/already-owned-in-another-lot pieces. Every rejection leaves links and quantities unchanged.
- [ ] **OWN-07e:** Test serialized history after sale/return/reacquisition; one current membership with old history retained. Public return activation remains deferred.
- [ ] **OWN-07f:** Test that owned-but-unreceived, received-but-unowned, and unlinked pieces cannot satisfy sale/pledge eligibility, including a different lot of the same item physically present.

### OWN-08 — Sale and collateral quantity integration tests

**Depends on:** OWN-06/07.

- [ ] **OWN-08a:** Test positive sale reservation against exact available quantity; reject a `0.001` excess and preserve owned quantity.
- [ ] **OWN-08b:** Test partial/full sale release only against the same source/lot and reject repeated/excess or another source's release.
- [ ] **OWN-08c:** Test reserved sale consumption reduces owned and its reservation atomically; exact exhaustion leaves no negative balance or stranded reservation.
- [ ] **OWN-08d:** Test authorized immediate unreserved sale helper behavior agreed in OWN-02d; it cannot consume another sale's reservation or pledged quantity.
- [ ] **OWN-08e:** Test pledge excludes reserved/unreceived/unlinked stock, preserves owned quantity, and records exact source/lot/pieces.
- [ ] **OWN-08f:** Test partial/full pledge release restores availability with no new lot, acquisition, or cash effect; wrong-source/repeated release rejects.
- [ ] **OWN-08g:** Test fully pledged forfeiture succeeds by checking the matching pledged balance, reduces owned and pledged together, and cannot remove another loan's pieces.
- [ ] **OWN-08h:** Test existing internal sale/purchase-return compatibility against original source/lot and allowed quantity. Do not enable public returns or implement their complete business accounting.

### OWN-09 — API, read, and gate tests

**Depends on:** OWN-02 accepted endpoint contracts, OWN-04 fixtures, and individual test expectation review. OWN-03 full-table review is deferred.

- [ ] **OWN-09a:** Test feature/permission combinations through real middleware for existing Ownership reads and manual acquisition; direct disabled writes have no effects.
- [ ] **OWN-09b:** Test linking read/write permissions and cross-tenant references; public payloads cannot select trusted paid-source or bypass context.
- [ ] **OWN-09c:** Test item pagination/search/stable order, including depleted and awaiting-receipt records, beyond old caps; test lot pagination separately.
- [ ] **OWN-09d:** Test history and serialized-candidate pagination/filtering; response includes source, reserved delta, and required provenance.
- [ ] **OWN-09e:** Test page-sized batch query behavior with one versus multiple items/lots; summaries omit unbounded lot/serialized collections.
- [ ] **OWN-09f:** Test photo URL/expiry reuse, null for missing files, and no raw storage path disclosure. Browser placeholder behavior is a separate confirmation.
- [ ] **OWN-09g:** Test public receipt-link stale-version 409/no writes, current-version success, and same-key replay before stale-version rejection under the accepted OWN-02f version contract.

### OWN-10 — Atomicity, idempotency, and concurrency tests

**Depends on:** OWN-04 and the relevant acquisition/quantity cases.

- [ ] **OWN-10a:** Test parent transaction rollback removes Ownership acquisition/links/movements and local completion/audit effects together with Inventory effects.
- [ ] **OWN-10b:** Test injected mutation/audit/completion failures and successful retry. Public replay preserves HTTP behavior; internal replay returns data without aborting its caller.
- [ ] **OWN-10c:** Test changed-payload key conflicts and uncertain-response replay without duplicate effects; keys are scoped by tenant/operation.
- [ ] **OWN-10d:** Test simultaneous acquisition of one purchase source and simultaneous link of one serialized piece. At most one current acquisition/link wins.
- [ ] **OWN-10e:** Test two operations compete for final lot quantity/piece; sale versus pledge cannot both consume availability.
- [ ] **OWN-10f:** Test opposite item/lot input ordering across two processes with bounded completion and correct final balances.
- [ ] **OWN-10g:** Test receipt/link versus sale eligibility under contention; the result must use committed current membership/custody, not a stale pre-lock snapshot.

### OWN-11 — Monthly summary tests

**Depends on:** OWN-04; monthly semantics below accepted in OWN-02g.

- [ ] **OWN-11a:** Test first checkpoint against full-ledger owned/reserved/pledged totals by item/lot, including paid goods awaiting receipt and zero balances.
- [ ] **OWN-11b:** Test next checkpoint uses the previous base; duplicate run skips completed item/month without changing balances or stock versions.
- [ ] **OWN-11c:** Test current balances after new acquisition, reserve, release, sale, pledge, and forfeiture on a previously summarized lot. Split transition cases for review.
- [ ] **OWN-11d:** Test current-month rows below the watermark, later IDs, and backdated occurrence dates remain included exactly once.
- [ ] **OWN-11e:** Test first deployment, missing preceding month, missed schedule, and older-checkpoint fallback.
- [ ] **OWN-11f:** Test tenant-local cutoff, timezone change, invalid/current/future month rejection, and context restoration after success/failure.
- [ ] **OWN-11g:** Test source-bound reservation/pledge and serialized membership remain correct across checkpoints; a total cannot authorize another source's release.
- [ ] **OWN-11h:** Test append-before-summary and summary-before-append races with independent connections; each direction is a separate review unit if needed.
- [ ] **OWN-11i:** Test concurrent summaries, failed item publication, no partial header/children, and resume skipping earlier committed items.
- [ ] **OWN-11j:** Test root job month pinning, tenant fan-out, queue/logging/overlap conventions, schedule registration, and explicit recovery command as separately reviewed cases.
- [ ] **OWN-11k:** Test query structure excludes checkpoint-covered old movements from operational balance reads and incremental summary input. Real EXPLAIN/timing remains OWN-33.

### OWN-12 — Review tests and initial results

**Depends on:** All OWN-04–11 tests written.

- [ ] **OWN-12a:** Reconcile individually reviewed tests and recorded expectation amendments against OWN-02 requirements, using OWN-03 as a provisional coverage reference. Identify missing requirements before feature implementation without requiring full-table approval.
- [ ] **OWN-12b:** When authorized and schema-ready, execute initial tests and record existing passes, expected missing-feature failures, and harness/environment blockers separately. Do not count syntax/load errors as behavioral coverage.
- [ ] **OWN-12c:** Obtain user acceptance of test expectations. Record database tests blocked by unprepared future schema as pending execution, not waived coverage.

## Stage 3 — Feature gates

### OWN-13 — Wire restrictions before new workflows

**Depends on:** OWN-12 test review; OWN-09 gate tests already written.

**Files:** Package feature configuration, additive gate migration if missing, `routes/api.php`; frontend navigation/routes reviewed separately.

- [ ] **OWN-13a:** Inventory existing `ownership_management`, `inventory_management`, Ownership permissions, and `ownership_manual_acquisition` before creating anything new.
- [ ] **OWN-13b:** Register inactive manual acquisition only if missing; preserve administrator overrides and existing grants. Review configuration and migration separately; execute neither migration nor seeding.
- [ ] **OWN-13c:** Apply the manual-acquisition gate at the existing write route. Retain authorized internal acquisition without exposing a public bypass.
- [ ] **OWN-13d:** Specify and wire receipt-link permission using an appropriate existing permission where sufficient; review any new permission as a separate unit before granting it.
- [ ] **OWN-13e:** Hide disabled manual actions/direct frontend routes and preserve staged/credit/delivery/return gates. Test public restrictions separately from internal availability.

## Stage 4 — Helper function writing

### OWN-14 — Messages and exact arithmetic

**Depends on:** OWN-05 tests and OWN-13.

**Files:** `lang/en/ownership.php`, `lang/mm/ownership.php`, existing exact quantity helper and cost utilities found during review.

- [ ] **OWN-14a:** Add matching localized keys for paid source, receipt-link state, source-bound quantity, and monthly-summary errors; reuse existing exception classes where suitable.
- [ ] **OWN-14b:** Connect Ownership quantity parsing/formatting to `InventoryQuantity`; remove no legacy call until its consumer is ready.
- [ ] **OWN-14c:** Implement the reviewed exact cost/value normalization only if no suitable utility exists; no floating-point arithmetic or unrelated money-system refactor.

### OWN-15 — Schema, models, and indexes

**Depends on:** Accepted OWN-02 design and previously written schema-dependent tests.

**Files:** Small additive migrations and models under the existing Ownership module; each schema responsibility is reviewed separately.

- [ ] **OWN-15a:** Add acquisition-to-purchase identity support needed before receipt, retaining existing item/code uniqueness and immutable source snapshots; review the proposed aggregate `update_key` as its own schema/model child before activation if accepted in OWN-02f.
- [ ] **OWN-15b:** Add receipt-to-lot quantity link records with stable source references and constraints against duplicate receipt allocation.
- [ ] **OWN-15c:** Add exact serialized lot membership/history and a database-enforced single-current-membership rule; permit historical reacquisition without duplicate current ownership.
- [ ] **OWN-15d:** Add or reuse source-bound sale/pledge commitment records needed for exact consumption/release. These track commitments, not a substitute continuously updated aggregate balance table.
- [ ] **OWN-15e:** Add monthly checkpoint header schema: tenant/item/month, timezone/cutoff, watermark, completion, and unique publication constraint.
- [ ] **OWN-15f:** Add monthly item/lot closing balance schema with exact owned/reserved/pledged quantities, including zero rows.
- [ ] **OWN-15g:** Inventory existing indexes; add only necessary tenant/item/lot/time/watermark and source/link lookup indexes. Review model casts/relations one model at a time.
- [ ] **OWN-15h:** Review up/down and fresh-deployment compatibility. Do not run migrations; separately prepared schema remains an execution prerequisite.
- [ ] **OWN-15i:** If accepted in OWN-02f, add/cast the Ownership aggregate `update_key` with default zero as a separate schema/model review. It advances on logical mutations, not monthly publication.

### OWN-16 — Typed contracts

**Depends on:** OWN-02 wire decisions, OWN-05 contract tests, OWN-14.

**Files:** Existing request DTO `OwnershipAcquisitionCreate`; new request/response DTOs using project conventions.

- [ ] **OWN-16a:** Update acquisition input to exact strings and explicit stable source/item context; coordinate constructors only at caller activation.
- [ ] **OWN-16b:** Define one receipt-link command with expected lot/source, exact quantity, selected pieces, and replay information.
- [ ] **OWN-16c:** Define quantity-operation commands for sale and pledge contexts separately. Trusted source context is never selected by public request input.
- [ ] **OWN-16d:** Define a mutation result returning exact affected lot/movement/link/piece references, not every lot on an item.
- [ ] **OWN-16e:** Define item filter/summary/page contracts; define detail separately without unbounded lot/piece collections.
- [ ] **OWN-16f:** Define lot, movement-history, and serialized-link filter/row/page contracts as separate units; include reserved quantities/deltas and pagination metadata.

### OWN-17 — Batched checkpoint-backed balance queries

**Depends on:** OWN-15, OWN-11 balance tests. All queries stay in `OwnershipRepository`.

- [ ] **OWN-17a:** Implement tenant-scoped latest-completed-checkpoint selection for requested item IDs/cutoff.
- [ ] **OWN-17b:** Implement exact opening item/lot balances from the selected headers; child records are scoped through the tenant-bound parent.
- [ ] **OWN-17c:** Implement uncovered movement delta query using both recording cutoff and watermark coverage.
- [ ] **OWN-17d:** Implement batched current item balances as base plus deltas; do not call one full-history sum per item.
- [ ] **OWN-17e:** Implement batched current lot balances and FIFO eligible-lot reads with explicit currency and receipt-link eligibility; no per-lot resource loop queries.
- [ ] **OWN-17f:** Implement bounded summary/detail/lot/history queries with stable order, page filters, and no unbounded eager loading. Review each query separately.

### OWN-18 — Source and link repository helpers

**Depends on:** OWN-15/16 and OWN-06–08 tests.

- [ ] **OWN-18a:** Implement tenant/item-bound acquisition source lookup and unique-source guard.
- [ ] **OWN-18b:** Implement exact receipt allocation totals/candidates and receipt-source lookup; do not infer eligibility from item-level physical totals.
- [ ] **OWN-18c:** Implement batched current serialized memberships and bounded piece candidates through Inventory services where another module owns the reads.
- [ ] **OWN-18d:** Implement source/lot-bound current sale commitment lookup, then pledge lookup as a separate unit. Old movements need not be re-summed from inception to release a current commitment.
- [ ] **OWN-18e:** Implement narrowly scoped persistence helpers for each link/commitment/membership operation. Query helpers contain no business transition rules.

### OWN-19 — Ordered locks and current reads

**Depends on:** OWN-10 concurrency tests and OWN-17/18.

- [ ] **OWN-19a:** Document one actual cross-module order against existing callers: complete Inventory item anchor sets, Ownership aggregates, lots/commitments, then the reviewed downstream custody locks. Reconcile with active Inventory order before activation; no opposite acquisition/consumption order.
- [ ] **OWN-19b:** Implement tenant-scoped aggregate anchor acquisition, including concurrent first acquisition when no aggregate exists, using the shared Inventory identity anchor where needed.
- [ ] **OWN-19c:** Implement deterministic lot/source-commitment locking for one operation; multi-item callers prelock complete sets.
- [ ] **OWN-19d:** Implement authoritative current balance/link/membership reads after locks; prevent repeatable-read snapshots from using stale checkpoint/base combinations.
- [ ] **OWN-19e:** Make monthly summarization and every Ownership movement append coordinate on the same aggregate anchor. Review repository append enforcement separately from caller helper use.

### OWN-20 — Validation helpers

**Depends on:** OWN-14–19 and their tests; review one validator per child, splitting further where needed.

- [ ] **OWN-20a:** Validate acquisition source eligibility through its owning service: same tenant/item, full settlement, active currency, allowed source, exact quantity/cost, and no duplicate source. Receipt is not a prerequisite.
- [ ] **OWN-20b:** Validate receipt/lot association and exact remaining quantity to link, preserving immutable acquisition snapshots.
- [ ] **OWN-20c:** Validate selected serialized pieces against live custody, tenant/item, lot capacity, and current membership; never perform one query per piece.
- [ ] **OWN-20d:** Validate sale reservation/consumption against exact eligible owned quantity, physical availability, and the same source's commitment.
- [ ] **OWN-20e:** Validate pledge availability and same-source release/forfeiture quantities. Forfeiture checks pledged quantity, not unpledged availability.
- [ ] **OWN-20f:** Validate internal return compatibility against original lot/pieces/source and permitted reversal quantity; this does not authorize a deferred public return.

### OWN-21 — Mutation and atomic operation helpers

**Depends on:** OWN-19/20; OWN-10 transaction/replay tests.

- [ ] **OWN-21a:** Implement an Ownership movement append helper with server recording timestamp, exact signed deltas, actor, occurrence date, and stable source references.
- [ ] **OWN-21b:** Implement narrowly scoped receipt/membership writes and current commitment updates; preserve old history and single-current-piece constraints.
- [ ] **OWN-21c:** Implement atomic mutation/completion/audit wrapper using existing services. Internal results return to the caller; public replay retains expected HTTP response behavior.
- [ ] **OWN-21d:** Reuse parent transaction/replay where sufficient; retain independently repeatable keyed operations where required. Retry deadlocks only at the owning outer boundary; do not introduce nested replay-abort or generated-key duplication.
- [ ] **OWN-21e:** Implement monthly checkpoint header/child publication helper inside one item transaction. It must not append a physical/Ownership movement or change entitlement.

## Stage 5 — Orchestration function writing

**Stage gate:** Do not build an orchestration function before its query, locking, validation, mutation, contract, and test dependencies are accepted. Wiring an endpoint or frontend action is a separate review from writing its service operation.

### OWN-22 — Acquisition orchestration

**Depends on:** OWN-06 tests, OWN-14–21.

**Files:** `OwnershipService` and source service adapter only as required.

- [ ] **OWN-22a:** Implement paid-source acquisition as a single reviewed orchestration function: qualify source, lock identity/aggregate, prevent duplicate acquisition, create lot and movement, return exact references. No physical receipt.
- [ ] **OWN-22b:** Preserve depleted aggregate history and valid later new-lot acquisition; coordinate UNIQUE and serialized constraints without erasing old records.
- [ ] **OWN-22c:** Adapt the existing internal/manual acquisition path only as needed for contracts; keep its public gate inactive.

### OWN-23 — Receipt and exact-piece linking orchestration

**Depends on:** OWN-07 tests and OWN-22.

- [ ] **OWN-23a:** Implement receipt quantity-to-lot linking: validate source and capacity, lock current state, persist links, return result; create no acquisition or cash effect.
- [ ] **OWN-23b:** Implement exact serialized linking with the same live checks and database constraints. Successful linking does not alter original lot quantity/cost.
- [ ] **OWN-23c:** Return pending-link quantity/state for reminder/action. Auto-link when the trusted immediate receipt supplies an unambiguous acquisition and exact pieces; retain manual resolution when not linked. User must see unresolved receipt links.

### OWN-24 — Sale quantity orchestration

**Depends on:** OWN-08a–d tests, OWN-19–23.

- [ ] **OWN-24a:** Implement sale reservation with positive exact availability guard, source/lot/piece binding, and atomic commitment/movement writes.
- [ ] **OWN-24b:** Implement partial/full same-source sale release without acquisition or physical receipt.
- [ ] **OWN-24c:** Implement atomic reserved sale consumption: reduce owned and its reservation together; coordinate physical issue through the business caller's transaction.
- [ ] **OWN-24d:** Implement the reviewed immediate sale helper contract without consuming another reservation; preserve existing internal return contracts separately.

### OWN-25 — Pledge quantity orchestration

**Depends on:** OWN-08e–h tests and shared helpers.

- [ ] **OWN-25a:** Implement source-bound pledge reservation preserving owned quantity and exact lot/piece references.
- [ ] **OWN-25b:** Implement same-source partial/full release; do not create a lot, acquisition, cash effect, or implied lender return.
- [ ] **OWN-25c:** Implement pledged forfeiture reducing owned and the matching pledge atomically, including fully pledged stock.
- [ ] **OWN-25d:** Return references needed by Part 8. Do not implement loan maturity decisions, lender custody transfers, or financial settlement in these helpers.

### OWN-26 — Read orchestration

**Depends on:** OWN-09 tests, OWN-16–18; no lazy database work inside DTO serialization.

- [ ] **OWN-26a:** Implement paginated item summary service using batch balances and Inventory projections, showing owned and physical quantities separately.
- [ ] **OWN-26b:** Implement current item detail; retain paid/unreceived and depleted records without claiming they are sellable stock.
- [ ] **OWN-26c:** Implement paginated lot service, then movement history as a separate unit; currency totals remain separate.
- [ ] **OWN-26d:** Implement paginated link candidates and pending-link reads; reuse Inventory signed photos/expiry without storage-path exposure.

### OWN-27 — Monthly summary service

**Depends on:** OWN-11 tests, OWN-17/19/21; details below.

- [ ] **OWN-27a:** Implement strict completed-month/timezone boundary resolution using the existing business clock.
- [ ] **OWN-27b:** Implement one-item checkpoint orchestration under the shared aggregate anchor: previous base, uncovered pre-cutoff deltas, watermark, header/lot balances, atomic commit.
- [ ] **OWN-27c:** Implement tenant summary orchestration outside existing business transactions, bounded item streaming, per-item commits, completed-item skipping, and context restoration.
- [ ] **OWN-27d:** Switch each operational balance/FIFO/read path to the same checkpoint-plus-tail helpers, one consumer per review. Preserve current source/receipt/piece checks.

### OWN-28 — Scheduled job and recovery command

**Depends on:** OWN-27 and OWN-11j tests.

- [ ] **OWN-28a:** Implement `SummarizeMonthlyOwnershipMovementsJob` tenant execution with existing scheduled queue, Redis availability, operation logging, and overlap conventions.
- [ ] **OWN-28b:** Implement root tenant fan-out and pin each completed month before dispatch; retries retain the same period.
- [ ] **OWN-28c:** Register monthly scheduling alongside existing financial/Inventory summary jobs. Proposed slot: day 2 at 01:00 UTC, after the existing 00:45 Inventory slot; independent correctness must not depend on job order.
- [ ] **OWN-28d:** Implement `ownership:summarize-monthly --tenant=TENANT_ID --month=YYYY-MM` recovery using the same validation and resumable service. Omitted options process each tenant's previous completed month. No destructive force-rebuild option.

### OWN-29 — Thin controller and API activation

**Depends on:** Matching accepted orchestration and prewritten OWN-09 tests.

**Files:** `OwnershipController`, `routes/api.php`; affected frontend service contracts only when coordinating activation.

- [ ] **OWN-29a:** Activate paginated item list, then detail as separate units. Keep existing endpoint paths where practical.
- [ ] **OWN-29b:** Activate paginated lots and movement history separately; validate query filters before calling typed services.
- [ ] **OWN-29c:** Activate paginated link-candidate reads, then the receipt-link write as separate units with source-safe input, permission, validation, and idempotency.
- [ ] **OWN-29d:** Coordinate any acquisition/caller result replacement with its consumers. Do not expose a public request that declares goods fully paid or enables loan/sale internal operations.

### OWN-30 — Existing caller adaptations

**Depends on:** Matching new contracts/operations. Review and activate one caller function at a time.

- [ ] **OWN-30a:** Change purchase acquisition linkage from receipt-only source to the reviewed fully paid purchase source. The fully paid trigger and receipt link must be activated together for the affected Quick Buy path; no temporary double acquisition.
- [ ] **OWN-30b:** Adapt receipt to link the existing lot and pieces rather than acquire again. Preserve caller-owned accounting/account records; document any financial timing changes for Part 4 review instead of silently duplicating Asset recognition.
- [ ] **OWN-30c:** Adapt Sales lot selection/reservation/consumption and exact piece selection. Public replacement must preserve paid/received/linked eligibility and distinct pieces across allocations.
- [ ] **OWN-30d:** Preserve internal return callers with typed exact results and original lot/source references. Public gates remain disabled.
- [ ] **OWN-30e:** Preserve Pawn source/caller compatibility without completing expired acquisition/redemption here; document Part 6 handoff.
- [ ] **OWN-30f:** Run authorized narrow contract/static checks; document deferred full Purchasing/Sales integration explicitly, with acceptance assigned to Parts 4/5.

### OWN-31 — Frontend connections

**Depends on:** Matching API contracts and approved UI behavior; no unrelated redesign.

**Files:** `lonepawn-frontend/src/modules/ownership/` services/types/pages/CSS; shared components/locales/routes only as needed.

- [ ] **OWN-31a:** Update typed frontend contracts and service functions for one endpoint at a time; preserve decimal strings in new writes.
- [ ] **OWN-31b:** Connect paginated item list/search and fresh detail-on-selection with stale-response protection. Keep depleted/history records accessible.
- [ ] **OWN-31c:** Connect paginated lots, then paginated movement history separately; show owned/reserved/pledged quantities and cost currencies clearly.
- [ ] **OWN-31d:** Show awaiting-receipt and received-but-unlinked quantities separately; do not label all owned quantity sellable.
- [ ] **OWN-31e:** Add the received-item linking reminder/action and paginated exact-piece selection. Refresh candidates/selection after mutation; retain one replay key for unchanged retries.
- [ ] **OWN-31f:** Reuse item photos, expiry handling, full-size opening, and the bundled default illustration for missing/failed images. No upload endpoint.
- [ ] **OWN-31g:** Add English/Myanmar feedback and separate mobile/desktop classes or components for the affected controls. Keep manual/deferred actions hidden and direct routes restricted.
- [ ] **OWN-31h:** Run authorized TypeScript/targeted lint checks and document existing unrelated failures separately. Browser confirmation remains Stage 6.

## Monthly Ownership checkpoints

### Decision and implementation status

Included by user request. Nothing is implemented or verified by this document. Tests precede schema/helpers/orchestration in OWN-11, OWN-15/17/19/21, and OWN-27/28 respectively.

### Quantity calculation

For a requested tenant/item/lot:

1. Select its latest completed checkpoint whose stored cutoff has passed.
2. Read exact closing owned, sale-reserved, and pledged quantities as the opening base.
3. Add each uncovered movement's corresponding signed deltas.
4. Derive available owned quantity from the three resulting quantities.
5. Separately check current source commitment, receipt allocation, serialized membership, and Inventory custody before sale/pledge/release/forfeiture.

A movement is covered only if its ID is at/below the checkpoint watermark **and** its server recording time is before the checkpoint UTC cutoff. The live tail includes IDs above the watermark **or** recording times on/after cutoff; missing recording times must not be silently dropped. Assign server UTC recording timestamps at append; business occurrence date does not define checkpoint membership.

Example: October closes with owned 100, reserved 10, pledged 20. A November acquisition of 5 and sale consuming 3 reserved units produce owned 102, reserved 7, pledged 20, available 75. Both changes remain live even if a November movement ID is below the watermark captured by a delayed October job.

### Storage and publication

- Header per tenant/Ownership item/closed month: timezone, UTC cutoff, watermark, completion, unique publication key.
- Exact item/lot closing owned/reserved/pledged quantities; retain exhausted/zero lots and source history. Item totals must agree with their lot totals.
- Source-specific current commitments and receipt/piece link records remain indexed operational data. Do not calculate another source's remaining pledge/reservation by subtracting only an item/month total.
- Do not materialize a second continuously updated aggregate Ownership balance table as an alternative to the movement ledger.
- Publish a header and all child balances atomically under the same item anchor used by every Ownership movement append. No partial checkpoint becomes visible.
- Use fresh/current source reads after obtaining anchors; prevent an earlier repeatable-read snapshot from mixing old deltas with a newer base. Reuse the reviewed Inventory strategy without copying its physical-location dimensions into Ownership.
- Summary maintenance creates no acquisition, cash, Accounting, or physical movement and does not change source snapshots or serialized memberships.

### Time, recovery, and scheduling

- A month is a tenant-local calendar month. Store the timezone and next local midnight converted to UTC as the exclusive cutoff. Existing checkpoints keep their stored boundary if timezone changes.
- Reject invalid/current/future months. Pin month in each tenant job payload before queue dispatch.
- Run tenant orchestration outside an existing business transaction. Stream a bounded item-ID register in chunks; commit one item at a time and restore TenantContext in `finally`.
- Duplicate jobs/reruns skip published item/months. Failure rolls back only the in-progress item's header/children; rerun resumes missing items.
- If a recent month is absent, use the latest older checkpoint plus its uncovered tail. Without any checkpoint, one initial per-item source-history calculation is unavoidable; do not repeat it after successful publication.
- Use the scheduled queue and existing financial/Inventory logging/overlap conventions. Deployment must supply a scheduler and worker; tests with faked dispatch do not establish deployed consumption.
- Recovery uses the same service and unique publication rules; do not overwrite published checkpoints or delete movements. A destructive correction/rebuild tool needs a separate approved plan.

### Performance acceptance

- Operational item/lot balance queries use requested IDs, selected opening bases, and uncovered deltas. No full-history PHP loading or per-row balance/resource loops.
- Compare one versus multiple items/lots per page; query count should follow bounded batching rather than one extra query per row.
- Explain representative MySQL queries with substantial old history and a recent tail. Record chosen indexes, examined rows, dataset size, and timing; a passing functional test alone does not prove the optimization.
- Include sources/links and monthly publication in the query review. A fast item summary must not hide an unbounded FIFO, source-release, or serialized-selection query.

## Stage 6 — Confirmations

### OWN-32 — Verify one scenario group at a time

**Depends on:** Connected matching implementation and user authorization to execute; an already prepared disposable MySQL schema.

- [ ] **OWN-32a:** Execute quantity/contract tests and map each failure to a requirement or explained test defect.
- [ ] **OWN-32b:** Execute acquisition tests, then receipt-link tests as separate review batches. Record exact balances and absence of unintended physical/financial effects.
- [ ] **OWN-32c:** Execute sale quantity tests, then pledge quantity tests separately; verify source-bound partial operations and exact pieces.
- [ ] **OWN-32d:** Execute API/tenant/permission/gate tests using real middleware; forbidden writes leave no rows/effects.
- [ ] **OWN-32e:** Execute rollback/idempotency tests, then independent-connection concurrency scenarios separately. Record final quantities and actual contention evidence.
- [ ] **OWN-32f:** Execute monthly equivalence/time/recovery tests, then monthly concurrency tests separately. Record fixture cleanup and context restoration.
- [ ] **OWN-32g:** Verify desktop/mobile paging, fresh selection, retry/conflict feedback, linking reminders/actions, and missing/failed/expired-photo placeholders when browser execution is authorized. No dev server without request.
- [ ] **OWN-32h:** Record scheduled-job registration versus actual deployed scheduler/worker execution separately. Deployment checks remain pending until observed.
- [ ] **OWN-32i:** Run the relevant Ownership and existing Inventory regression suite once after coordinated wiring. Do not repeatedly broaden tests without a new failure/change/risk.

### OWN-33 — Confirm query plans and performance

**Depends on:** OWN-17/26/27 implementation, prepared representative data, execution authorization.

- [ ] **OWN-33a:** Record MySQL EXPLAIN and measured item-page/lot/FIFO queries with old history plus a small live tail.
- [ ] **OWN-33b:** Record source commitment/link-candidate query plans; verify indexes avoid repeated full-history calculations.
- [ ] **OWN-33c:** Measure first versus incremental monthly publication, missed-month catch-up, and large-lot batching. Record dataset and timings, not unsupported speed claims.
- [ ] **OWN-33d:** Review findings and apply only test-backed necessary fixes; keep unperformed measurements pending.

### OWN-34 — Final complexity review and completion record

**Depends on:** Complete connected implementation and recorded OWN-32/33 results or explicit remaining verification.

- [ ] **OWN-34a:** Trace one acquisition-to-receipt-link-to-sale chain and one pledge/release/forfeiture primitive chain; document transaction/source/audit ownership. Do not claim deferred business end-to-end acceptance.
- [ ] **OWN-34b:** Review duplicate validation, repeated locks/queries, nested replay keys/transactions, obsolete array readers, and DTO/consumer consistency. Propose any cleanup as a small separate review unit with tests first.
- [ ] **OWN-34c:** Assign remaining full workflows to Parts 4–8 and retain unpaid-sale/delivery placeholders. Reconcile checklist parent/child statuses against actual evidence.
- [ ] **OWN-34d:** Record implemented changes, source-timing amendment, receipt linking, images/placeholders, monthly summaries, test evidence, and pending verification in the main module plan.
- [ ] **OWN-34e:** Obtain final user review. Distinguish implementation complete from verification complete; do not mark unexecuted scenarios passed.

## Acceptance verification register

No tests have been written or executed as part of creating this checklist. Fill this table after each authorized verification unit; a proposed test file is not evidence.

| Scenario group | Required evidence | Result / remaining work |
|---|---|---|
| Exact values/contracts | Quantity/cost edges, tracking modes, typed serialization | Pending |
| Acquisition | Fully paid before receipt, unpaid rejection, source uniqueness, immutable costs, reacquisition | Pending |
| Receipt/pieces | No double acquisition, exact allocation, unknown-to-known serials, single current membership | Pending |
| Sale quantities | Source-bound reserve/release/consume, received/linked eligibility, exact exhaustion | Pending |
| Pledge quantities | Owned preserved, same-source release, fully pledged forfeiture | Pending |
| API/security | Pagination, tenant/permission/feature restrictions, no public bypass | Pending |
| Atomicity/replay | Outer rollback, injected failures, stable replay, payload conflict | Pending |
| Concurrency | Real MySQL contention and valid final item/lot/piece quantities | Pending |
| Monthly checkpoints | Full-ledger equivalence, tail coverage, time policy, races, resume/recovery | Pending |
| Performance | Representative EXPLAIN/timing, bounded query counts, no repeated inception scans | Pending |
| Frontend/images | Paging, linking reminders, live detail, mobile/desktop, photo fallback | Pending |
| Scheduler deployment | Actual scheduled-queue consumption/retry/logging beyond faked dispatch | Pending |
| Full business workflows | Purchasing/Sales/Pawn/Packing/Business Loan complete chains | Deferred to owning parts; not claimed verified here |

## Review log

Add one row per child review unit. Update the status register and child checkbox consistently. If a batch is authorized, still record each child unit's outcome separately.

| Date | Step/substep | Files/functions changed | Review outcome | Verification performed or pending | Next dependency |
|---|---|---|---|---|---|
| 2026-10-20 | Checklist creation | This document only | Prepared from session decisions; detailed units await review | No executable tests, production code, migrations, jobs, or runtime operations performed | OWN-01a |
| 2026-10-20 | OWN-01a purchase timing analysis | This checklist; read-only inspection of Purchasing/Ownership acquisition and receipt functions | Analysis prepared / awaiting user acceptance; checkbox remains unchecked | Static trace found receipt-triggered acquisition, no payment-triggered acquisition, and no Quick Buy full-payment guard at the reviewed boundary. No runtime tests or database operations; documentation whitespace checked | User review of OWN-01a; OWN-01b after acceptance |
| 2026-10-20 | OWN-01a acceptance | This checklist | User accepted through “ok next”; OWN-01a checked | No additional runtime verification | Remaining OWN-01 analysis |
| 2026-10-20 | OWN-01b–e analysis batch | This checklist; read-only Sales/return/Ownership/loan/API/frontend inspection | Full OWN-01 analysis requested by user; findings prepared / awaiting acceptance. OWN-01b–e remain unchecked | Static flow, transaction/financial ownership, safeguard/query/UI/gate gaps and later-part handoffs recorded. Documentation checks only; no production edits or runtime tests | User review, then OWN-02a |
| 2026-10-20 | OWN-01 acceptance | This checklist | User requested the next step, all OWN-02; OWN-01b–e accepted and checked | Analysis acceptance only; no runtime verification | OWN-02a–h |
| 2026-10-20 | OWN-02a–h design batch | This checklist; read-only source/Inventory/currency/permissions/response/idempotency inspection | Q&A design prepared as requested; confirmed business rules distinguished from proposed implementation answers; awaiting review | Canonical purchase source, zero-stock identity, receipt/piece/source records, exact values, APIs/versioning, monthly summaries, and UI defaults specified. One planned stale-link test child added; no executable tests or production changes | User review/amendments, then OWN-03 |
| 2026-10-20 | OWN-02 acceptance | This checklist | User approved Q&A through “ok fine by me , next 03”; OWN-02a–h checked | Design acceptance only; no implementation or runtime verification | OWN-03 |
| 2026-10-20 | OWN-03a–d test matrix | This checklist; read-only existing test harness/preflight/exception inspection | 49 scenario groups mapped to accepted requirements; categories, no-write expectations, schema prerequisites, review/freeze rules prepared / awaiting acceptance | Documentation consistency/whitespace checked. No executable tests written, no database preflight run, no source/schema changes or runtime execution | User review, then OWN-04a |
| 2026-10-20 | OWN-03 review deferral | This checklist | User deferred full-table review and will review/change each test and expected behavior during test writing. Matrix remains provisional; OWN-03 is not marked accepted/complete and does not block OWN-04 | Documentation-only workflow/dependency update; no tests written/run or production/schema changes | OWN-04a, then per-test Q&A review during Stage 2 |
| 2026-10-20 | OWN-04a disposable MySQL preflight | `tests/Support/ownership_database_preflight.php`; this checklist | Prepared / awaiting review; reuses existing Inventory connection/preflight unchanged | PHP syntax passed; read-only MySQL 8.0.40 metadata inspection passed existing baselines and reported absent receipt/monthly tables and aggregate version. Full new-schema readiness intentionally pending OWN-15; no fixtures, PHPUnit, migrations, or production changes | User review, then OWN-04b |
| 2026-10-20 | OWN-04a acceptance | This checklist | User marked the unit done; OWN-04a checked. OWN-04b–d remain not started | Prior syntax/read-only preflight evidence retained; future OWN-15 schema dependencies unchanged. No checks rerun for this status update | OWN-04b rollback fixture |
| 2026-10-20 | OWN-04b rollback fixture | `OwnershipFixture`, `OwnershipMySqlTestCase`; this checklist | Implemented / awaiting review in user-authorized full OWN-04 batch | Syntax/static validation only. Real tenant/source/Inventory fixture helpers written without running them or invoking acquisition as setup evidence | User review; runtime checks with later tests |
| 2026-10-20 | OWN-04c committed fixture | `CommittedOwnershipFixture`; this checklist | Implemented / awaiting review | Exact fixture-ID cleanup, FK-preserving dependency retries, worker stopping, and final ID assertions implemented; no committed data created or cleanup executed | User review; runtime checks with later tests |
| 2026-10-20 | OWN-04d worker coordination | `OwnershipWorkerProcess`, Ownership worker, shared Inventory worker bootstrap, existing Inventory worker include; this checklist | Implemented / awaiting review | Seven-file PHP syntax and whitespace checks; extraction/body static comparison passed. No workers or feature tests run; actual contention and Windows pipe behavior remain pending | User review, then OWN-05a |
| 2026-10-20 | OWN-04b–d acceptance | This checklist | User requested “mark done”; OWN-04b–d checked and OWN-04 implementation/review marked complete | Prior syntax/static evidence retained; no checks rerun. Fixture/cleanup/worker runtime verification and future schema readiness remain pending | OWN-05a quantity tests |

## Part 2 completion

- [ ] Requirement decisions/contracts and each implemented test's expected behavior are reviewed and accepted, including user amendments during test writing. Reconcile OWN-03 as a reference; full-table approval is not required under the recorded deferral.
- [ ] All agreed executable unit/integration/API/concurrency/monthly tests were written and reviewed before the corresponding production changes.
- [ ] Feature gates, helpers, orchestration, controllers/APIs, caller adaptations, and frontend are implemented and reviewed in dependency order.
- [ ] Fully paid ownership is independent of receipt; receipt linking never creates duplicate ownership.
- [ ] Exact lot/piece/source safeguards and atomic quantity changes satisfy their requirements, not merely the existing test implementation.
- [ ] Monthly summaries are wired into operational balances; normal reads do not repeatedly calculate all history from inception.
- [ ] Required acceptance/performance checks have recorded results; unperformed checks remain explicitly pending.
- [ ] Full business workflow handoffs and future unpaid-sale/delivery placeholders are recorded in the owning plan parts.
- [ ] Main module plan records all delivered amendments, image/linking behavior, monthly summaries, and remaining verification.
- [ ] User accepts Part 2 implementation status before advancing; final verified completion is claimed only when all applicable checks are satisfied.
