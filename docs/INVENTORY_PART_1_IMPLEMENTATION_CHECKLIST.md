# LonePawn 2.0 — Part 1 Inventory Implementation Checklist

## Purpose

Track the approved Inventory plan as small, dependency-first review steps. This checklist supplements `LONEPAWN_2_0_MODULE_PLAN.md`; it does not replace the release scope or authorize implementation by itself.

All checkboxes start unchecked. Existing code is not considered complete until it has been reviewed against the agreed behavior. No implementation or verification is implied by creating this tracker.

Paths are relative to `lonepawn-server` unless prefixed with `lonepawn-frontend/`.

## Working rules

- Implement one review unit per turn, normally one responsibility across one to three files.
- Complete dependencies before implementing their caller or main orchestration function.
- Split steps containing multiple methods/contracts/endpoints into separate review units as listed below.
- Finish a unit, report its changes and verification status, and wait for user review before advancing.
- Distinguish implementation, review acceptance, and verification. Do not check a parent step complete while any applicable child item is unfinished.
- Add new contracts/helpers without changing existing behavior prematurely.
- Replace public contracts only when affected consumers are ready; coordinate activation rather than leaving incompatible contracts wired together.
- Accounting, Financial Account operations, logging, auditing, idempotency, notifications, and cache updates belong to the caller's workflow. Reuse existing services; do not develop them as separate modules.
- Do not run migrations, a development server, or tests without the required user instruction. Record unperformed verification as pending.
- Do not expand this part into Packing, Business Loan collateral, Pawn redemption, balance projections, or unrelated shared-service refactoring.

**Batch authorization:** On 2026-10-18 the user requested implementation through INV-08 in one turn. INV-05–08 are therefore prepared together for review; acceptance remains pending for each step.

## Agreed decisions

- Replace current Inventory read/write contracts and update affected consumers together; no legacy public-array fallback.
- Paginate item summaries, movement history, and serialized-unit reads: default 25, maximum 100 per page.
- Keep measurement-unit options separate from physical serialized-unit reads.
- Locations may deactivate only when they hold no stock and have no active reservations. The default location remains active.
- Use exact three-decimal quantity arithmetic; never allow a `0.001` stock shortfall.
- Use optimistic versions for stale public edits/moves and pessimistic locks for authoritative stock/custody changes.
- Target MySQL/InnoDB for concurrency verification. SQLite does not establish row-lock correctness.
- Keep movement history authoritative; do not introduce a stock balance cache/projection in Part 1.
- Keep standalone receipt, issue, and adjustment behind inactive `inventory_manual_operations`. Internal business operations remain usable by authorized callers.

## Dependency map

```text
Messages / existing exceptions
    → exact quantity helper
    → schema / model versions / indexes
    → typed contracts
    → repository queries
    → validation / locking / mutation helpers
    → individual orchestration functions
    → endpoints / frontend / caller wiring
    → acceptance verification
```

Read and mutation branches can share completed foundations. The sequence below is a review order; explicit dependencies determine readiness.

## Status register

Suggested states: Not started, In progress, Implemented / awaiting review, Accepted / verification pending, Complete, Blocked.

| Step | Deliverable | Status |
|---|---|---|
| INV-01 | Quantity messages | Complete |
| INV-02 | Exact quantity helper | Complete |
| INV-03 | Location version schema/model | Complete |
| INV-04 | Item version schema/model | Complete |
| INV-05 | Query indexes | Complete |
| INV-06 | Location contracts | Complete |
| INV-07 | Stock command/result contracts | Complete |
| INV-08 | Paginated read contracts | Complete |
| INV-09 | Tenant/version-aware lookups | In progress — reservation lookup accepted |
| INV-10 | Batched balance query | Not started |
| INV-11 | Reservation quantity/unit queries | Not started |
| INV-12 | Unit custody/identifier queries | Not started |
| INV-13 | Paginated repository reads | Not started |
| INV-14 | Tracking/reference validation | Not started |
| INV-15 | Ordered locking helpers | Not started |
| INV-16 | Availability/reservation validation | Not started |
| INV-17 | Movement/reservation mutation helpers | Not started |
| INV-18 | Transaction/idempotency/audit handling | Not started |
| INV-19 | Location creation orchestration | Not started |
| INV-20 | Location update orchestration | Not started |
| INV-21 | Read service functions | Not started |
| INV-22 | Move orchestration | Not started |
| INV-23 | Internal receipt orchestration | Not started |
| INV-24 | Reservation create/release orchestration | Not started |
| INV-25 | Internal issue orchestration | Not started |
| INV-26 | Endpoints and gates | Not started |
| INV-27 | Frontend connections | Not started |
| INV-28 | Existing caller adaptations | Not started |
| INV-29 | Acceptance verification | Not started |

## Foundation steps

### INV-01 — Inventory quantity messages

**Depends on:** Existing translation system and `InvalidTenantRequest`.

**Files:** New `lang/en/inventory.php`, `lang/mm/inventory.php`.

- [x] Add matching English/Myanmar keys for invalid format, non-positive quantity, excess precision, range overflow, UNIQUE quantity, and SERIALIZED whole-number requirements.
- [x] Reuse the existing exception mechanism; add no new exception class for this dependency.
- [x] Confirm key parity and review wording.
- [x] Obtain review acceptance; change no Inventory behavior in this step.

### INV-02 — Exact quantity helper

**Depends on:** INV-01.

**Files:** New `app/Support/InventoryQuantity.php`.

- [x] Parse quantities into integer thousandths and format decimal strings.
- [x] Reject invalid, non-finite, zero, negative, over-precision, and out-of-range input.
- [x] Enforce UNIQUE quantity one and SERIALIZED whole-number quantity.
- [x] Provide exact comparison without permissive floating-point tolerance.
- [x] Review boundary examples including `0.001`, exact exhaustion, and overflow.
- [x] Obtain review acceptance; do not wire the helper into orchestration yet.

### INV-03 — Location optimistic version

**Depends on:** Existing location schema.

**Files:** New additive migration; `app/Models/InventoryModule/InventoryLocation.php`.

- [x] Add non-null `update_key` with default zero.
- [x] Add model typing/cast support.
- [x] Review migration up/down behavior and compatibility without executing it.
- [x] Obtain review acceptance.

### INV-04 — Item stock version

**Depends on:** Existing item schema.

**Files:** New additive migration; `app/Models/InventoryModule/InventoryItem.php`.

- [x] Add non-null `update_key` with default zero.
- [x] Add model typing/cast support.
- [x] Document that physical stock and reservation changes increment this version.
- [x] Review migration up/down behavior without executing it.
- [x] Obtain review acceptance.

### INV-05 — Query indexes

**Depends on:** Existing movement and reservation schemas.

**Files:** New additive index migration.

- [x] Inventory existing indexes before adding anything.
- [x] Add latest-custody index `(tenant_id, inventory_unit_id, id)`.
- [x] Add needed item/location incoming and outgoing movement indexes.
- [x] Reuse existing item-name, history, and reservation indexes where adequate.
- [x] Retain tenant/code, tenant/location-name, and tenant/identifier uniqueness.
- [x] Do not add simplistic source uniqueness that blocks reservations spanning lots/locations.
- [x] Review migration without running it.
- [x] Obtain review acceptance.

**Index inventory (schema review only):** Existing `inventory_items(tenant_id, name)`, `inv_mov_item_time_idx`, `inv_mov_loc_pair_idx`, `inv_res_item_loc_status_ix`, and `inv_res_source_ix` are retained. Tenant/code, tenant/location-name, and tenant/identifier unique constraints remain intact. The additive migration adds `inv_mov_unit_latest_idx (tenant_id, inventory_unit_id, id)`, `inv_mov_item_to_loc_idx (tenant_id, inventory_item_id, to_location_id)`, and `inv_mov_item_from_loc_idx (tenant_id, inventory_item_id, from_location_id)`. Existing reservation indexes support item/location queries; location-wide deactivation queries must be reviewed with their final shape in INV-09/11 and query plans in INV-29. Actual database index state and query plans have not been inspected.

## Contract steps

### INV-06 — Location request/response contracts

**Depends on:** INV-01, INV-03; existing `BaseDataObject` conventions.

**Files:** New DTOs in `app/DataObjects/RequestObjects/` and `ResponseObjects/`.

Implement each checkbox as its own review unit when needed.

- [x] Define `InventoryLocationCreate`.
- [x] Define `InventoryLocationUpdate`, including expected version.
- [x] Define `InventoryLocationDetail`, including active/default/sellable flags and `update_key`.
- [x] Review normalization, public code references, and serialization.
- [x] Obtain review acceptance for all location contracts.

### INV-07 — Stock command/result contracts

**Depends on:** INV-02, INV-04; existing DTO conventions.

**Files:** New request/response DTOs.

- [x] Define `InventoryReceiveRequest`.
- [x] Define `InventoryMoveRequest`, including expected item version for public moves.
- [x] Define `InventoryIssueRequest` with explicit reservation and internal operation context.
- [x] Define `InventoryReservationRequest`.
- [x] Define `InventoryMutationResult` with item/version and operation-specific movement/unit codes.
- [x] Specify receipt results identify only newly received units, not every unit on the item; enforce in INV-23/28.
- [x] Keep public input from selecting a privileged internal bypass context.
- [x] Statically review each contract separately.
- [x] Obtain review acceptance.

**Supporting contracts:** `InventoryStockCommand` centralizes exact quantity normalization and selected-unit code normalization. `InventoryIssueContext` is assigned by trusted services, never hydrated from public input; context values do not authorize an operation or enable a deferred feature. Tracking/reference/permission checks remain in their planned service steps. Idempotency keys enter public factories as a separate argument resolved by the controller.

### INV-08 — Paginated read contracts

**Depends on:** Existing pagination conventions; INV-04, INV-06, INV-07 as applicable.

**Files:** New request filters and response DTOs.

- [x] Define `InventoryListFilter` and `InventoryUnitFilter`.
- [x] Define `InventoryItemDetail` and paginated item summaries.
- [x] Define `InventoryItemListPage`, `InventoryUnitListPage`, and `InventoryMovementListPage`.
- [x] Set default page size 25 and maximum 100; validate filters and document fixed stable ordering for INV-13.
- [x] Exclude unbounded serialized-unit collections from summaries/detail.
- [x] Include quantities, versions, location active/sellable state, and required movement provenance.
- [x] Statically review each contract separately.
- [x] Obtain review acceptance.

**Supporting contracts:** `InventoryPageFilter` also handles history pagination. `InventoryReadPage` shares typed row validation and flat pagination metadata. `InventoryItemSummary`, `InventoryLocationBalance`, `InventoryUnitDetail`, and `InventoryMovementDetail` provide typed rows without lazy database reads. Summary availability is sellable availability; per-location availability is unreserved physical stock, with active/sellable flags supplied separately. Measurement-unit options remain separate. Stable ordering will be implemented in INV-13: item name/ID, unit ID, and movement occurrence time/ID. No existing endpoint or caller uses these contracts yet.

## Repository steps

### INV-09 — Tenant-scoped/version-aware lookups

**Depends on:** INV-03–08 as required by each lookup.

**Files:** Individual methods in `app/Repository/InventoryRepository.php`.

- [x] Review item-by-code lookup and optional locking.
- [x] Obtain acceptance for item-by-code lookup (INV-09a).
- [x] Review location-by-code lookup and shared/exclusive locking support.
- [x] Obtain acceptance for location-by-code lookup (INV-09b).
- [ ] Review reservation/unit lookups with explicit tenant/item binding.
    - [x] Add reservation lookup expected-item binding using TenantContext (INV-09c).
    - [x] Obtain acceptance for reservation lookup (INV-09c).
    - [ ] Review serialized-unit lookup tenant/item binding (next review unit).
- [ ] Add conditional version updates and affected-row conflict handling.
- [ ] Preserve unique-constraint enforcement and translate known conflicts at the service boundary.
- [ ] Review one method at a time and obtain acceptance.

**INV-09a — item lookup:** Reused `findItemByCode(...)`; no query behavior or signature change was needed. Documented explicit tenant/code filtering, the existing unique index, full-model/version return, nullable result, and caller-owned transaction requirement for `FOR UPDATE`. No version comparison or increment is performed by a lookup. Static review only; runtime locking verification remains pending in INV-29. Next review unit: location lookup shared/exclusive locking.

**INV-09b — location lookup:** Extended the existing `findLocationByCode(...)` with optional `sharedLock`. Default reads remain unlocked; existing third-argument `true` still requests an exclusive lock. `sharedLock: true` requests a shared lock when the exclusive flag is false. Both flags true use the stronger exclusive lock. Tenant/code filtering and nullable full-model return are retained. Locks require the caller's transaction; shared-lock caller wiring and ordered multi-location locking remain in INV-15. Static review only; MySQL concurrency verification remains pending in INV-29.

**Current tenant scoping revision:** At the user's request, both INV-09 lookups now rely on the existing `BelongToTenant` global scope backed by `TenantContext`. Removed their explicit tenant-ID parameters and duplicate tenant predicates; updated all current service call sites. Both lookups throw the existing `TenantNotFound` exception before querying if context is absent. Current signatures are `findItemByCode(string $code, bool $lock = false)` and `findLocationByCode(string $code, bool $lock = false, bool $sharedLock = false)`. Thus the exclusive lock flag is now the second argument. The earlier descriptions/log rows record the original implementation; this revision supersedes their explicit tenant argument details. Other repository methods remain for their own review units.

**INV-09c — reservation lookup:** Reused `reservationByCode(string $code, bool $lock = false, ?int $inventoryItemId = null)`. The existing model global scope uses TenantContext; missing context throws TenantNotFound before querying. The optional expected item ID adds an item predicate, returning null on mismatch. Status/location/source/remaining-quantity validation remains owned by services. All four existing callers use the new context-based signature; expected-item filtering will be wired with explicit missing/mismatched reservation rejection in INV-16, avoiding premature changes to existing null fallthrough behavior. Code-only lookup remains available for release flows. Static review only; lock ordering and MySQL verification remain in INV-15/29.

### INV-10 — Optimized movement balance queries

**Depends on:** INV-02, INV-05, INV-09.

**Files:** Individual repository balance methods.

- [ ] Add grouped incoming/outgoing balances for a page of item IDs and relevant locations.
- [ ] Keep decimal values exact through conversion to quantity units.
- [ ] Separate browse aggregates from authoritative write-side current reads.
- [ ] Do not add a balance cache/projection or change application-wide isolation.
- [ ] Review zero-stock, exact-exhaustion, and multiple-location results; obtain acceptance.

### INV-11 — Reservation queries

**Depends on:** INV-05, INV-09.

**Files:** Individual repository reservation methods.

- [ ] Batch active remaining quantities by item/location.
- [ ] Batch active reserved-unit membership.
- [ ] Provide current locking reads for mutation decisions.
- [ ] Avoid repeated per-unit/per-location queries.
- [ ] Review tenant/state filtering and obtain acceptance.

### INV-12 — Unit custody and identifier queries

**Depends on:** INV-05, INV-09.

**Files:** Individual repository unit methods.

- [ ] Batch latest movement IDs per unit and join custody/location records.
- [ ] Batch tenant-scoped identifier existence lookup.
- [ ] Lock selected units in ascending ID order.
- [ ] Preserve identifiers such as `"0"`; handle trimming, nullable identifiers, and database uniqueness consistently.
- [ ] Review one query at a time and obtain acceptance.

### INV-13 — Paginated repository reads

**Depends on:** INV-08, INV-10–12.

**Files:** Individual repository item/history/unit read methods.

- [ ] Implement paginated item summary retrieval without loading every unit.
- [ ] Implement paginated history ordered by occurrence time and ID.
- [ ] Implement paginated serialized units with location/availability filters.
- [ ] Batch related Catalog/unit/location reads.
- [ ] Preserve access beyond the former 100-item/500-movement caps.
- [ ] Review each read independently and obtain acceptance.

## Service dependency steps

### INV-14 — Quantity, tracking, and reference validation

**Depends on:** INV-02, INV-07, INV-09.

**Files:** Individual helpers in `app/Services/TenantModule/InventoryService.php`; Inventory message additions where required.

- [ ] Use the exact quantity helper for internal and public commands.
- [ ] Validate tracking mode, unit count, distinct units, and tenant references.
- [ ] Permit freeform serialized goods with a valid unit and optional Catalog reference.
- [ ] Validate active destination and operation-specific location rules.
- [ ] Translate known identifier/name uniqueness conflicts into domain errors.
- [ ] Review each helper and obtain acceptance.

### INV-15 — Ordered pessimistic locking

**Depends on:** INV-09–12.

**Files:** Individual repository/service locking helpers.

- [ ] Lock existing item anchors in ascending ID order.
- [ ] Lock locations in ascending ID order: shared for stock operations, exclusive for location edits.
- [ ] Lock reservations, then selected serialized units, in ascending ID order.
- [ ] Recheck bindings/state after acquiring locks.
- [ ] Keep locks until the owning transaction completes.
- [ ] Establish multi-item caller prelocking requirements.
- [ ] Use authoritative current reads; do not rely on stale nonlocking snapshots.
- [ ] Review receive/move/issue/release ordering and obtain acceptance.

### INV-16 — Availability and reservation validation

**Depends on:** INV-10–12, INV-14–15.

**Files:** Individual Inventory service helpers.

- [ ] Compare availability exactly, including requests for `0.001`.
- [ ] Require sellable locations for sale reservations/fulfilment.
- [ ] Reject missing supplied reservations rather than treating them as absent.
- [ ] Validate tenant, item, location, source, status, and remaining quantity.
- [ ] Validate exact serialized membership and current custody.
- [ ] Keep the future Packing/collateral restriction contract explicit without implementing those modules now.
- [ ] Review each helper and obtain acceptance.

### INV-17 — Movement/reservation mutation helpers

**Depends on:** INV-07, INV-14–16.

**Files:** Individual Inventory repository/service helpers; reservation/movement models only where needed.

- [ ] Append movements with actor, timestamp, source, unit, and idempotency references.
- [ ] Prevent application-level rewriting/deletion of movement history.
- [ ] Increment item versions for physical/reservation changes.
- [ ] Remove exact consumed serialized codes during issue.
- [ ] Require exact unit codes for partial serialized release; support full release of all remaining codes.
- [ ] Keep serialized remaining quantity equal to remaining unit-code count.
- [ ] Return only affected movement/unit codes.
- [ ] Review each mutation helper and obtain acceptance.

### INV-18 — Inventory transaction, idempotency, and audit wrapper

**Depends on:** Completed contracts/helpers; existing idempotency/audit services.

**Files:** Inventory operation wrapper and required Inventory messages.

- [ ] Reserve and complete Inventory idempotency with the business transaction.
- [ ] Preserve same-key replay and different-payload conflict semantics.
- [ ] Avoid committing a standalone processing record before stock work starts.
- [ ] Bound recognized deadlock retries to the outermost operation, maximum three attempts.
- [ ] Do not retry validation, version conflicts, or arbitrary failures.
- [ ] Keep public Inventory audit atomic; avoid duplicate primary audits for internal caller operations.
- [ ] Keep failure diagnostics and post-commit cache/notification responsibilities explicit.
- [ ] Review wrapper behavior and obtain acceptance.

## Orchestration steps — implement only after dependencies are accepted

### INV-19 — Create location

**Depends on:** INV-06, INV-09, INV-18; existing code generator.

**Files:** `InventoryService::createLocation(...)` and its immediate integration only.

- [ ] Normalize/validate name, type, and sellability.
- [ ] Generate code and persist inside a transaction.
- [ ] Handle concurrent duplicate names through domain errors.
- [ ] Write location audit and idempotency result atomically.
- [ ] Obtain review acceptance.

### INV-20 — Update location

**Depends on:** INV-03, INV-06, INV-09–11, INV-15, INV-18.

**Files:** `InventoryService::updateLocationByCode(...)`.

- [ ] Acquire exclusive location lock and compare expected `update_key`.
- [ ] Reject stale edits with HTTP 409.
- [ ] Keep default location active.
- [ ] Require zero stock and no active reservations before deactivation.
- [ ] Validate restriction changes against active operation requirements.
- [ ] Increment version, record audit, and complete the transaction.
- [ ] Obtain review acceptance.

### INV-21 — Read services

**Depends on:** INV-08, INV-13; completed response construction helpers.

**Files:** Individual Inventory read-service functions.

- [ ] Implement item summary page service.
- [ ] Implement item detail service.
- [ ] Implement movement history page service.
- [ ] Implement serialized-unit page service.
- [ ] Preserve measurement-unit options as a separate endpoint/service meaning.
- [ ] Confirm no per-item/unit query loops; obtain acceptance for each function.

### INV-22 — Move stock

**Depends on:** INV-07, INV-14–18.

**Files:** `InventoryService::move(...)`.

- [ ] Validate public item version/idempotency and distinct locations.
- [ ] Acquire ordered locks and recheck current stock, unit custody, and restrictions.
- [ ] Reject stale client state with 409 and insufficient quantity without side effects.
- [ ] Append move effects, conserve stock, increment version, and write Inventory audit.
- [ ] Commit and return mutation result; obtain review acceptance.

### INV-23 — Internal receipt

**Depends on:** INV-07, INV-14–18; completed item-creation/Catalog/unit lookup helpers.

**Files:** `InventoryService::receive(...)`; necessary receipt-result consumer wiring kept narrow.

- [ ] Create or resolve the item and validate tracking/identifiers.
- [ ] Follow the ordered locking protocol.
- [ ] Append receipt movements and return only newly received unit codes.
- [ ] Increment version and preserve caller-owned financial/audit responsibilities.
- [ ] Keep existing Pawn receipt compatibility without implementing redemption.
- [ ] Obtain review acceptance.

### INV-24 — Reservation creation and release

**Depends on:** INV-07, INV-15–18.

**Files:** One Inventory reservation orchestration function per review unit.

- [ ] Implement sale reservation creation using current sellable availability.
- [ ] Implement full reservation release.
- [ ] Implement partial quantity release and exact partial serialized release.
- [ ] Reject stale bindings/state and keep unit/quantity/version invariants.
- [ ] Obtain review acceptance for each function.

### INV-25 — Internal issue

**Depends on:** INV-07, INV-14–18, INV-24.

**Files:** `InventoryService::issue(...)`.

- [ ] Validate supplied reservation and internal source context.
- [ ] Recheck availability/custody under locks.
- [ ] Issue exact quantity/units without a tolerance-based shortfall.
- [ ] Consume reservation quantity/codes consistently and increment version.
- [ ] Return affected records; retain caller-owned accounting/account/audit completion.
- [ ] Obtain review acceptance.

## Public integration steps

### INV-26 — Controller endpoints and feature gates

**Depends on:** The completed service function for each endpoint.

**Files:** Individual `InventoryController` actions, `routes/api.php`, feature configuration/migration as separate review units.

- [ ] Register inactive `inventory_manual_operations` consistently.
- [ ] Wire paginated item/detail/history/unit contracts one endpoint at a time.
- [ ] Keep `/inventory/units` as measurement-unit options; use an item-scoped route for serialized units.
- [ ] Wire location create/update with version/idempotency rules.
- [ ] Wire public move with item version/idempotency rules.
- [ ] Gate standalone receive/issue/adjust without blocking internal business calls.
- [ ] Replace old response contracts only with their consumers ready.
- [ ] Review direct API restrictions and obtain acceptance per endpoint/unit.

### INV-27 — Frontend connection

**Depends on:** Completed matching endpoint contracts and service operations.

**Files:** `lonepawn-frontend/src/modules/inventory/types.ts`, `services/inventoryService.ts`, `pages/InventoryPage.tsx`; route/navigation/CSS/locales only as needed for each unit.

- [ ] Update typed summary/detail/page/result contracts.
- [ ] Connect paginated item list and detail-on-selection.
- [ ] Connect movement history pagination.
- [ ] Connect paginated unit selection and location filtering.
- [ ] Connect location create/edit and empty-location deactivation feedback.
- [ ] Connect moves using expected item version.
- [ ] Preserve one idempotency key across retries of the same intent; rotate for changed/new intent.
- [ ] Handle stale conflicts by refreshing detail instead of silently resubmitting.
- [ ] Refresh selection/units after mutations; do not reuse stale list objects.
- [ ] Hide deferred manual actions/routes and provide translated feedback.
- [ ] Use separate responsive classes where needed without redesigning the page.
- [ ] Obtain review acceptance for each individual read/action flow.

### INV-28 — Existing caller adaptations

**Depends on:** Completed new Inventory contracts and operations.

**Files:** Individual changes in Purchasing, Sales, and Ownership services.

- [ ] Purchasing: use receipt-specific affected unit codes instead of the item's full unit collection.
- [ ] Purchasing: prelock existing Inventory item sets deterministically.
- [ ] Sales: use bounded availability reads and keep unit selections distinct across allocations.
- [ ] Sales: use typed reservation/mutation results and ordered item prelocking.
- [ ] Ownership: adapt options/detail/lookup consumption without changing its business rules.
- [ ] Coordinate each changed return shape with its caller before activation; avoid duplicate wiring work.
- [ ] Confirm Pawn receipt compatibility; leave redemption/acquisition changes for Part 6.
- [ ] Obtain review acceptance for each caller adaptation.

## Acceptance verification

### INV-29 — Verify one scenario group at a time

**Depends on:** Connected backend/frontend/caller implementation. Verification requiring execution remains pending until authorized.

**Proposed test files:** `InventoryQuantityTest.php`, `InventoryReadTest.php`, `InventoryLocationTest.php`, `InventoryMovementTest.php`, `InventoryReservationTest.php`, `InventoryConcurrencyTest.php` under the appropriate existing test directories.

- [ ] Quantity: zero versus `0.001`, exact exhaustion, fractions, precision/range overflow, and non-finite values.
- [ ] Tracking: invalid UNIQUE/SERIALIZED fractions, duplicate/wrong-item/wrong-location/issued units, identifiers after trimming and `"0"`.
- [ ] Locations: same-location move, inactive destination, non-sellable sale stock, default deactivation, stocked/reserved location deactivation.
- [ ] Optimistic concurrency: stale location edits and stale public moves return 409 without writes.
- [ ] Reservations: invalid supplied code, cross-tenant binding, partial issue/release, no stale serialized codes.
- [ ] Pessimistic concurrency: two operations compete for final stock/unit; only valid quantity succeeds.
- [ ] Lock ordering: concurrent receive/issue and location deactivation do not allow invalid custody or opposite-order locking.
- [ ] Idempotency: same key/payload replays, changed payload conflicts, retry after uncertain response does not duplicate effects.
- [ ] Rollback: failure before audit/reservation completion and outer caller rollback remove internal Inventory effects.
- [ ] Reads: no per-item/unit query loops; summaries do not load all units; history/items beyond old caps remain accessible.
- [ ] Integration: receipt returns only new units; Sales and Ownership work with replacement contracts.
- [ ] Query plans: inspect indexed batch reads on a prepared representative MySQL dataset.
- [ ] Gates: direct calls to disabled standalone operations produce no side effects.
- [ ] Record results and unresolved findings; obtain final Part 1 review acceptance.

Concurrency verification requires independent MySQL connections and a separately prepared disposable schema. Do not run migrations as part of verification. SQLite alone is insufficient for locking claims.

## Review log

Add a row after each review unit. Keep the step status register and checkboxes consistent with the actual work.

| Date | Step/substep | Files/functions changed | Review outcome | Verification performed or pending | Next dependency |
|---|---|---|---|---|---|
| — | — | — | — | — | INV-01 |
| 2026-10-18 | INV-01 | `lang/en/inventory.php`, `lang/mm/inventory.php` | Implemented; user review pending | Static review of six matching keys and wording; no tests run | INV-02 after INV-01 acceptance |
| 2026-10-18 | INV-01 acceptance | — | Accepted by user before INV-02 | No additional execution | INV-02 |
| 2026-10-18 | INV-02 | `app/Support/InventoryQuantity.php` | Implemented; user review pending | Static review of parsing, scale, tracking rules, zero-balance formatting, and bounds; no tests run | INV-03 after INV-02 acceptance |
| 2026-10-18 | INV-02 acceptance | — | Reviewed and accepted by user | No additional execution | INV-03 |
| 2026-10-18 | INV-03 | `database/migrations/2026_10_18_000001_add_update_key_to_inventory_locations.php`, `app/Models/InventoryModule/InventoryLocation.php` | Implemented; user review pending | Static review: non-null unsigned version defaults to zero; rollback removes only this column; integer cast follows existing Catalog convention. No migration or tests run | INV-04 after INV-03 acceptance |

| 2026-10-18 | INV-03 acceptance | — | Reviewed and accepted by user | No additional execution | INV-04 |
| 2026-10-18 | INV-04 | `database/migrations/2026_10_18_000002_add_update_key_to_inventory_items.php`, `app/Models/InventoryModule/InventoryItem.php` | Implemented; user review pending | Static review: non-null unsigned version defaults to zero; rollback removes only this column; integer cast and future stock/reservation increment responsibility documented. No migration or tests run | INV-05 after INV-04 acceptance |
| 2026-10-18 | INV-04 acceptance | — | Reviewed and accepted by user | No additional execution | INV-05 |
| 2026-10-18 | INV-05 | `database/migrations/2026_10_18_000003_add_inventory_movement_query_indexes.php` | Implemented; user review pending | Static comparison with existing schema indexes; up/down reviewed. No migration, database inspection, or query-plan execution | INV-09/10/11/12 |
| 2026-10-18 | INV-06 | `InventoryLocationCreate`, `InventoryLocationUpdate`, `InventoryLocationDetail` DTOs | Implemented; user review pending | Static review of partial edits, explicit false, version bounds, public codes, and BaseDataObject serialization; no tests | INV-09/19/20 |
| 2026-10-18 | INV-07 | Stock request/result DTOs, shared `InventoryStockCommand`, `InventoryIssueContext` | Implemented; user review pending | Static review of exact quantities, expected versions, nullable reservation, trusted context/source boundaries, and affected-unit result semantics; no tests | INV-09/14 onward |
| 2026-10-18 | INV-08 | Inventory read filters, typed row DTOs, and page DTOs | Implemented; user review pending | Static review of pagination bounds, row types, provenance, no embedded unit collection, and query-free serialization; no tests | INV-09/13 |

| 2026-10-18 | INV-05–08 acceptance | — | Reviewed and accepted by user | No additional execution; runtime verification remains pending | INV-09 |
| 2026-10-18 | INV-09a | `InventoryRepository::findItemByCode` | Static review and documentation complete; user review pending | Reviewed explicit tenant/code predicates, existing uniqueness, nullable return, version availability, and optional locking. No tests or database access | INV-09 location lookup after acceptance |

| 2026-10-18 | INV-09a acceptance | — | Reviewed and accepted by user | No additional execution | INV-09b |
| 2026-10-18 | INV-09b | `InventoryRepository::findLocationByCode` | Implemented; user review pending | Static review of tenant/code predicates, unlocked/shared/exclusive branches, exclusive precedence, and existing caller compatibility; no tests or database access | INV-09 reservation/unit bindings after acceptance |

| 2026-10-18 | INV-09 tenant scoping revision | Item/location lookups and their `InventoryService` callers | Implemented per user instruction; review pending | Static call-site review; uses existing TenantContext/model scope and missing-context exception. No tests or database access | Continue INV-09 after review |

| 2026-10-18 | INV-09b and tenant scoping revision acceptance | — | Reviewed and accepted by user | No additional execution | INV-09c |
| 2026-10-18 | INV-09c | `InventoryRepository::reservationByCode`, four `InventoryService` call sites | Implemented; user review pending | Static review of TenantContext scope, missing-context rejection, optional expected-item binding, nullable return, exclusive lock, and caller signatures. No tests or database access | Serialized-unit lookup after acceptance |

| 2026-10-18 | INV-09c acceptance | — | Reviewed and accepted by user | No additional execution | Serialized-unit lookup |

## Part 1 completion

- [ ] All INV-01 through INV-29 applicable deliverables are implemented and reviewed.
- [ ] All required acceptance verification is completed; unverified claims are not marked passed.
- [ ] Required caller integrations and public contract replacement are connected consistently.
- [ ] Remaining findings are assigned to their later module parts.
- [ ] User accepts Part 1 before advancing to Part 2 — Ownership.
