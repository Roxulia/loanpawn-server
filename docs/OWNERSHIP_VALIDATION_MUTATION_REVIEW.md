# OWN-20 and OWN-21 validation and mutation helpers

## Status and scope

The user accepted OWN-19 through "mark as done and next 2 phase", authorizing OWN-20 and OWN-21 as a batch. The subsequent instruction authorized related tests and fixes. These two phases are implemented for review; their checkboxes remain unchecked until accepted. Acceptance of OWN-19 did not turn previously unverified scenarios into passes.

No migrations, schema rebuilds, development server, scheduler or queue worker were started. Tests used the existing guarded disposable MySQL database. Independent test workers are part of the explicitly authorized concurrency tests.

## OWN-20

| Unit | Reused or delivered validation |
|---|---|
| 20a | `PurchaseSourceService::qualifiedLine` remains the payment/source authority. `AcquisitionValidationService::purchase` extracts the existing tenant/item/tracking/unit, exact quantity/cost and actual active payment-currency checks. Acquisition retains canonical duplicate-source and UNIQUE current-balance guards. Receipt remains independent. |
| 20b | `ReceiptValidationService::association` checks actual receipt, canonical purchase source, item and tenant. `capacity` reuses original linked quantities, so sale consumption does not reopen receipt capacity or alter acquisition snapshots. |
| 20c | Existing receipt piece validation is extracted into `ReceiptValidationService::pieces`, with explicit duplicate selection rejection for internal callers. `QuantityValidationService::pieces` reuses batched Inventory custody, current memberships and exact source holds. Consumption additionally requires current custody. No per-piece query loop is introduced. |
| 20d | `QuantityValidationService` reuses existing source, piece and cross-lot sale-line capacity checks; adds explicit same-source commitment and exact balance checks. Current reserve/release/consume consumers use these helpers. Physical availability and received backing checks remain in the existing quantity operation. |
| 20e | `balance` distinguishes free capacity from reserved/pledged capacity: a fully pledged lot can pass forfeiture validation despite zero free availability. `commitment` verifies the exact tenant/item/lot/kind/type/source tuple. Pledge reserve uses the existing trusted resolver DTO. Release/forfeiture orchestration/API signatures remain OWN-25; helper tests do not claim those operations are activated. |
| 20f | `ReturnValidationService` resolves real internal return records through their owning services/repositories before module anchors. It verifies original parent/order/item/lot/pieces, exact source limit, previous same-source reversals and current serialized memberships. Existing purchase-return callers use this validation and the retained-backing mutation. Public return gates are unchanged. Full serialized sale-return/reacquisition orchestration remains later caller work. |

Return source queries required explicit existing Sales table mappings on `SaleReturn`/`SaleReturnLine`. The UNIQUE fixture subsequently exposed the same inferred-table mismatch on `SaleOrderReservation`; it now maps to the existing `sales_order_reservations` table. No tables were created or renamed.

## OWN-21

| Unit | Reused or delivered mutation boundary |
|---|---|
| 21a | `MovementService::transition` supplies one exact signed-delta mapping shared by active quantity operations and pure unit tests. `MovementService::append` normalizes positive quantity and signed component strings, rejects components larger than the operation quantity, retains occurrence/source references and supplies actor/time defaults. All four application append sites use it. Repository append still enforces the shared aggregate/transaction and now explicitly sets the authoritative UTC server recording timestamp, independently of the event date. |
| 21b | Reuse OWN-18's model-specific receipt/commitment updates and membership/hold ending helpers; they retain history and narrow permitted columns. `QuantityService::consumeReceivedBacking` reuses the existing consumption helper for validated purchase returns, reducing remaining backing without reopening original receipt allocation or deleting memberships. |
| 21c | `OperationService::run` wraps mutation, audit and optional idempotency completion in one transaction. Acquisition, receipt linking and exact quantity operations use it. Audit failure or completion failure rolls back mutation. The stored response retains the agreed `data` envelope, while internal replay hydrates and returns `OwnershipResult` normally. Explicit HTTP replay uses the existing exception mechanism when requested by an adapter. |
| 21d | Caller transactions retain ownership; nested work uses Laravel's existing savepoint behavior, with no helper-level deadlock retry and no generated replay key. Operations with independent keys retain them. Existing legacy array/public replay behavior remains until coordinated caller replacement. Internal replay can continue its parent transaction. |
| 21e | `CheckpointService::publish` validates supplied month/cutoff/lot closings and holds the shared aggregate anchor for one item transaction. `CheckpointQueries` publishes header/children in chunks of 500, computes totals with SQL DECIMAL sums, rejects overcommitted closings, and returns an existing publication unchanged on retry. It changes no entitlement, version, movement, physical custody or audit rows. Monthly discovery, checkpoint-derived balance calculation, tenant context, scheduler/recovery and publication contention remain OWN-27/28. |

The checkpoint helper accepts already calculated closing rows; its tests prove persistence, validation and rollback, not monthly full-ledger equivalence or performance.

## Test scenarios and fixes

Eight focused `OwnershipHelperTest` cases cover:

- Internal replay runs mutation/audit only once and returns the same references.
- Failure after a real state write rolls back, then the same key can retry.
- Fully pledged capacity permits exact forfeiture validation while insufficient source remaining rejects.
- Known checkpoint closings publish without changing business rows, and duplicate publication preserves the original.
- A foreign-item lot cannot contribute to a closing.
- An injected failure after an actual checkpoint child INSERT rolls back header and children.
- Another loan cannot borrow the persisted source commitment.
- Backdated occurrence cannot backdate the server recording timestamp.

The first exploratory 100-test selection exposed actual in-scope issues: replay storage lacked the agreed envelope; legacy insufficient-balance rejection used the wrong exception contract; internal purchase returns did not reduce retained receipt backing. These are fixed. The previously failing UNIQUE fixtures reserved Inventory before Ownership; setup now follows the accepted entitlement-before-custody sequence, with every original assertion retained. Both UNIQUE lifecycle scenarios now pass after the existing Sales table mapping correction.

## Execution evidence

- Focused new helper suite: **8 tests / 30 assertions passed**.
- Final related helper/acquisition/source/link/eligibility/sale/return/atomicity/replay/UNIQUE/lot/FIFO selection: **142 tests / 2,705 assertions passed**. This includes the new helper tests and all four real FIFO contention cases.
- Additional supported receipt-link, pledge-reservation, sale membership-history and generic independent-worker contention selection: **11 tests / 350 assertions passed**. The six generic contention provider cases retain their observed-lock-wait requirements.

The final feature selections therefore cover **153 distinct tests / 3,055 assertions**, without counting focused reruns again. The related unit selection passed **329 tests / 683 assertions**. Across feature and unit selections, **482 distinct tests / 3,738 assertions passed**. All 24 changed/new PHP files passed syntax checks; tracked diff and new-file whitespace checks passed.

The initial related unit run exposed 34 failures and eight risky results in the old `OwnershipTransitionTest` harness. It mocked legacy aggregate/SUM lookups and full public methods that no longer match the implemented helpers. Its fixture harness now supplies explicit balance/source inputs to the actual `QuantityValidationService` and production `MovementService::transition` helper. Every original success, exact-boundary, invalid-input and excess-quantity scenario, literal movement type, exact quantity/delta expectation and source-code assertion remains. Only the obsolete mock lookup/sentinel orchestration was removed. These are pure helper tests; the real feature suites retain source/custody/tenant/persistence/rollback evidence. This harness fix belongs to current OWN-20/21; later missing APIs and the photo test remain untouched as requested.

## Explicit later-phase dependencies

The exploratory selection also reached ten cases requiring later work. They remain unmodified and are not represented as passing or fixed:

- Two `OwnershipReceiptLinkTest` cases call absent trusted `linkPurchaseReceipt` orchestration (OWN-23).
- Three `OwnershipPledgeTest` cases require exact serialized release/forfeiture API signatures and orchestration (OWN-25).
- Four serialized sale-return history cases require the exact `restoreForSaleReturn(... serializedItemCodes:)` internal adapter and episode restoration; one repurchase case requires Inventory receipt reuse of a previously issued serialized identity (coordinated caller/receipt work in OWN-23/30 and Purchasing acceptance).

These are executable dependencies rather than waived assertions. No test was deleted, weakened, marked skipped or altered to accept missing behavior. The complete Ownership suite, public API/UI activation, monthly publisher and complete Purchasing/Sales financial workflows are not claimed complete. The earlier unrelated Pawn signed-photo comparison is unchanged.

## Import and constructor-injection follow-up

At the user's request, inline fully qualified types in Ownership services/repository traits/models/controller now use namespace imports. `OwnershipService` declares its nine stable module collaborators through constructor injection: lock, FIFO, quantity, acquisition, read, receipt linking, balance, movement and return validation services. Its delegations and compatibility helpers use those properties instead of `app(Class::class)`. Existing workflows, DTO contracts, transaction boundaries and later-phase dependencies are unchanged.

Verification after the refactor: **167 related feature tests / 5,894 assertions passed**, including real FIFO contention, helper rollback/replay, acquisition/link/quantity/return, current balance, pagination and read cases; **329 related unit tests / 683 assertions passed**. These two selections total **496 tests / 6,577 assertions**. The focused eight helper cases also passed before the broader selections and are not counted twice. PHP syntax and whitespace checks passed. A source scan found no remaining inline App/Illuminate/Carbon fully qualified references or `app(...)` calls in the scoped Ownership implementation. Shared base/framework container behavior is outside this refactor. No later-phase or photo failures were changed.

## Quantity action readability and repeated-work follow-up

The user prioritized repeated/nested validation and lookups, plus separating the combined workflows, in `applyOwnershipQuantityAction`. This refactor is limited to those priorities; the separate re-reservation capacity calculation and action/source compatibility concerns are unchanged.

- Common source/Inventory/Ownership preparation now produces a typed internal `OwnershipQuantityState`. It retains current locked records and exact quantities without exposing raw models through public responses.
- The main function now coordinates preparation, validation, shared custody/piece selection, a named reservation or release/consumption helper, and movement recording. Mutation helpers reuse the existing commitment and receipt-history write functions.
- Existing balance/commitment validators remain authoritative. The additional null/remaining check and second available-balance comparison were removed because the existing validators already enforce them. The separate received-backing and physical-custody constraints remain.
- Commitment kind/type/source are filtered once in the repository; the in-memory selection now selects only the target lot.
- Inventory is resolved directly from the discovered ownership record's Inventory ID, avoiding a lazy relationship query and subsequent code-based lookup. The current Ownership re-read after the Inventory anchor is intentionally retained for lock/snapshot correctness. The operation wrapper's audit resolution is unchanged.
- Fungible actions no longer query serialized memberships or piece commitments. Serialized actions retain the same exact-piece/source validation and lock order. Empty membership sets also avoid a pointless piece-hold query.

A new real-database query regression first failed on the unnecessary serialized-state reads and passed after the optimization. It also verifies the literal final owned/reserved balances. Earlier success, rejection, rollback, replay and independent-worker cases remain unchanged. No migration, development server, later-phase repair or reservation-capacity adjustment was added.

Final quantity refactor verification: **150 related feature tests / 2,943 assertions passed**, including the new query regression and existing observed-contention cases. Changed PHP syntax, tracked diff whitespace, and new-file whitespace checks passed. This is the selected regression scope, not a full Ownership or later-phase completion claim.

## Remaining helper readability, names and repeated-query follow-up

The user authorized the remaining OWN-20/21 helper refactors and descriptive names. The work keeps the existing business rules, error contracts, tenant/source guards, lock order, transaction boundaries and later-phase exclusions.

| Previous helper | Current name |
|---|---|
| Return validation `source` / `validate` | `resolveReturnSource` / `validateReturnEligibility` |
| Checkpoint `publish` | `publishMonthlyOwnershipCheckpoint` |
| Quantity validation `commitment` / `balance` / `source` | `validateSourceCommitment` / `validateQuantityActionBalance` / `validateQuantityActionSource` |
| Quantity validation `pieces` / `sourceCapacity` | `selectEligibleOwnershipPieces` / `validateSaleLineReservationCapacity` |
| Acquisition validation `purchase` | `validatePurchaseAcquisition` |
| Receipt validation `association` / `capacity` / `pieces` | `validateReceiptLotAssociation` / `validateReceiptLinkCapacity` / `validateReceiptPieceSelection` |
| Movement `transition` / `append` | `buildOwnershipQuantityTransition` / `appendOwnershipMovement` |
| Operation `run` | `runAtomicOwnershipOperation` |
| Purchase-return backing `consumeReceivedBacking` | `consumePurchaseReturnReceiptBacking` |

All production and test callers were updated. No old-name forwarding aliases or new public business endpoints were added.

Return validation now reads as tenant/lot validation, original purchase or sale evidence, remaining reversal allowance, exact piece-code validation and current membership selection. The internal `OwnershipReturnSelection` carries that locked selection into purchase-return backing consumption, removing the second Inventory custody and membership lookup. The exact selected-count guard is retained for partial serialized requests. Source-type and original allocation rules are unchanged; serialized sale-return orchestration remains later work.

Checkpoint publication now delegates period/coverage validation and lot-row normalization to named helpers. Repository methods separately insert the header, insert bounded child batches, read exact closing facts and save fixed totals. Totals and overcommitment detection use one SQL aggregate scan, retaining SQL DECIMAL arithmetic, the 500-row insertion bound and rollback on invalid closings. No monthly publisher/scheduler is implemented by this cleanup.

Quantity piece checks now use named selection-shape, uncommitted-availability and source-hold checks instead of one compound conditional. The reservation capacity formula itself is unchanged.

Acquisition validation returns exact `OwnershipAcquisitionValues` so lot creation reuses the already-normalized unit cost and quantity. Payment-account currency identities are validated in one tenant-scoped batch through `TenantCurrencyService` and `CurrencyRepository`; separate code visibility and all missing/inactive/foreign identity guards remain.

Atomic operations now accept an internal `OwnershipMutationResult` from actual acquisition/link/quantity mutations. It contains their existing owner model and unchanged public response, eliminating the audit-only owner lookup. The wrapper checks target tenant/code correspondence. Response-only callbacks retain the previous fallback lookup, and stored/public/internal replay behavior remains unchanged. Raw owner models never enter the public response.

Five new real-database regressions verify: one checkpoint balance scan, overcommitted-checkpoint rollback, no audit-target reselect, one currency identity batch lookup, and one serialized membership selection across return validation/consumption. Existing test expectations are retained; fixture extension only supplies optional serialized piece codes to the existing return-source helper.

Final verification: **180 related feature tests / 6,173 assertions passed** and **329 related unit tests / 683 assertions passed**, for **509 tests / 6,856 assertions** across those selections. Focused reruns are not counted twice. Syntax, reference scans and whitespace checks passed. No migrations or development services were run. Deferred workflows, the separate reservation-capacity/action compatibility findings, and the unrelated photo check remain untouched.
