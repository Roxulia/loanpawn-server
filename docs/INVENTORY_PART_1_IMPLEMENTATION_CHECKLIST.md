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
- Do not expand this part into Packing, Business Loan collateral, Pawn redemption, a continuously updated balance table, or unrelated shared-service refactoring. Monthly checkpoints are now explicitly included below.

**Batch authorization:** On 2026-10-18 the user requested implementation through INV-08 in one turn. INV-05–08 are therefore prepared together for review; acceptance remains pending for each step.

**INV-10–14 batch authorization:** The user subsequently requested INV-10 through INV-14 together. The required serialized-unit lookup dependency is included as INV-09d. These units are prepared for review together; the remaining INV-09 conditional version writes are still pending and are not required by these read queries. No acceptance or runtime verification is implied by this batch.

- Continue implementing and wiring INV-01 through INV-29 before the overall complexity reanalysis in INV-30. Defer that broad simplification pass until the complete workflows and callers are connected.

## Agreed decisions

### Inventory naming

- Operation names include their target object: `moveStock`, `issueStock`, and `adjustStock`, consistently across services, controller actions, and frontend methods.
- **Measurement Unit:** Catalog measurement such as kg, gram, or piece. Inventory measurement-option methods use `measurementUnits()` and frontend `InventoryMeasurementUnitOption`.
- **Serialized Item:** An individually tracked physical piece under a SERIALIZED Inventory item. The model is `InventorySerializedItem`; its read contracts are `InventorySerializedItemFilter`, `InventorySerializedItemDetail`, and `InventorySerializedItemListPage`.
- Physical query helpers use explicit names such as `serializedItemPage`, `findSerializedItemsByCodesForUpdate`, and `latestMovementsForSerializedItems`. New command/result properties use `serializedItemCodes` / `serializedItemCode`.
- No compatibility mappings remain for the old physical-item terminology. Fresh-schema migrations define `inventory_serialized_items`, movement FK `inventory_serialized_item_id`, and business JSON fields `serialized_item_codes`. Receipt input uses `serialized_item_identifiers`; Inventory item responses use `serialized_items`. Inventory, Purchasing, Sales, Delivery persistence, and frontend callers use the new fields. The model uses Laravel table naming conventions; generated serialized-item codes use prefix `SI`. Measurement options use `/inventory/measurement-units`. No database migration was executed.
- Historical mentions of physical units in this tracker mean Serialized Items. Earlier release-plan documents remain unchanged.

### Implementation decisions

- Replace current Inventory read/write contracts and update affected consumers together; no legacy public-array fallback.
- Paginate item summaries, movement history, and serialized-unit reads: default 25, maximum 100 per page.
- Keep measurement-unit options separate from physical serialized-unit reads.
- Locations may deactivate only when they hold no stock and have no active reservations. The default location remains active.
- Use exact three-decimal quantity arithmetic; never allow a `0.001` stock shortfall.
- Use optimistic versions for stale public edits/moves and pessimistic locks for authoritative stock/custody changes.
- Target MySQL/InnoDB for concurrency verification. SQLite does not establish row-lock correctness.
- Keep movement history authoritative. Include derived monthly quantity checkpoints and scheduled summarization under the approved scope amendment; no continuously updated balance table.
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

**Monthly scope amendment:** The user requested monthly scheduled summarization and specified the `SummarizeMonthlyFinancialMovementsJob` pattern. Monthly checkpoint schema, queries, append coordination, summary service, scheduled job, recovery command, and balance-query integration are included as INV-MS-01–07. See [Monthly inventory checkpoints](#monthly-inventory-checkpoints) for exact cutoff/watermark semantics and pending verification. These changes supersede the earlier no-projection restriction without adding a continuously updated stock table.

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
| INV-09 | Tenant/version-aware lookups | Accepted / verification pending |
| INV-10 | Batched balance query | Accepted / verification pending |
| INV-11 | Reservation quantity/unit queries | Accepted / verification pending |
| INV-12 | Unit custody/identifier queries | Accepted / verification pending |
| INV-13 | Paginated repository reads | Accepted / verification pending |
| INV-14 | Tracking/reference validation | Accepted / verification pending |
| INV-MS-01–07 | Monthly quantity checkpoints, scheduled summarizer, and balance integration | Implemented / awaiting review |
| INV-DTO-01 | Typed Inventory operation inputs and coordinated callers | Accepted / verification pending |
| INV-15 | Ordered locking helpers | Accepted / verification pending - dependency helpers only |
| INV-16 | Availability/reservation validation | Accepted / verification pending - dependency helpers only |
| INV-17 | Movement/reservation mutation helpers | Accepted / verification pending - dependency helpers only |
| INV-18 | Transaction/idempotency/audit handling | Accepted / verification pending - dependency helpers only |
| INV-19 | Location creation orchestration | Accepted / verification pending |
| INV-20 | Location update orchestration | Accepted / verification pending |
| INV-21 | Read service functions | Accepted / verification pending |
| INV-22 | Move orchestration | Accepted / verification pending |
| INV-23 | Internal receipt orchestration | Accepted / verification pending |
| INV-24 | Reservation create/release orchestration | Accepted / verification pending |
| INV-25 | Internal issue orchestration | Accepted / verification pending |
| INV-ADJ-01 | Adjustment orchestration using shared stock helpers | Implemented / awaiting review |
| INV-26 | Endpoints and gates | In progress — request DTO validation wired |
| INV-27 | Frontend connections | In progress — move version field wired |
| INV-28 | Existing caller adaptations | In progress — Inventory request DTOs wired |
| INV-29 | Acceptance verification | Not started |
| INV-30 | Overall operation, locking, and validation reanalysis/refactor | Deferred until INV-01-29 and all caller wiring are complete |

## Foundation steps

### INV-DTO-01 — request-contract activation amendment

At the user's request, InventoryService mutation inputs now use the request DTOs created in INV-06/07, matching Pawn ManagementService's named-property style. [DTO usage and contract integration](#dto-usage-and-contract-integration) maps each DTO to its current service/controller/business caller and explicitly lists response contracts that remain pending.

- [x] Replace generic operation arrays for location create/update, receipt, move, issue, reservation, adjustment, and sale returns.
- [x] Replace generic item-creation input and movement metadata with typed receipt/context objects.
- [x] Serialize typed inputs only at the idempotency boundary; remove hidden `_idempotency_record_id` request keys.
- [x] Add missing adjustment, sale-return, and movement-context contracts.
- [x] Update controllers, Purchasing, Sales, SaleReturn, and Pawn custody together.
- [x] Activate required move/location version inputs, stale checks, and item version increments for physical/reservation changes; coordinate the frontend move payload and exposed resource versions.
- [x] Reuse the existing item-ID locking helper under its general name `findItemByIdForUpdate`; update monthly checkpoint caller accordingly.
- [x] Run PHP syntax checks on 13 affected files; no tests, migrations, or database operations.
- [x] Obtain review acceptance.

This amendment partially wires INV-09/17/20/22/26–28 dependencies. It does not complete their remaining ordered-lock, exact availability, atomic idempotency/audit, gating, pagination, or UI conflict work. Current response arrays remain until their separately planned result/read contract activation; response DTOs are not claimed as integrated.

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
- [x] Add latest-custody index `(tenant_id, inventory_serialized_item_id, id)`.
- [x] Add needed item/location incoming and outgoing movement indexes.
- [x] Reuse existing item-name, history, and reservation indexes where adequate.
- [x] Retain tenant/code, tenant/location-name, and tenant/identifier uniqueness.
- [x] Do not add simplistic source uniqueness that blocks reservations spanning lots/locations.
- [x] Review migration without running it.
- [x] Obtain review acceptance.

**Index inventory (schema review only):** Existing `inventory_items(tenant_id, name)`, `inv_mov_item_time_idx`, `inv_mov_loc_pair_idx`, `inv_res_item_loc_status_ix`, and `inv_res_source_ix` are retained. Tenant/code, tenant/location-name, and tenant/identifier unique constraints remain intact. The additive migration adds `inv_mov_serialized_item_latest_idx (tenant_id, inventory_serialized_item_id, id)`, `inv_mov_item_to_loc_idx (tenant_id, inventory_item_id, to_location_id)`, and `inv_mov_item_from_loc_idx (tenant_id, inventory_item_id, from_location_id)`. Existing reservation indexes support item/location queries; location-wide deactivation queries must be reviewed with their final shape in INV-09/11 and query plans in INV-29. Actual database index state and query plans have not been inspected.

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

- [x] Define `InventoryListFilter` and `InventorySerializedItemFilter`.
- [x] Define `InventoryItemDetail` and paginated item summaries.
- [x] Define `InventoryItemListPage`, `InventorySerializedItemListPage`, and `InventoryMovementListPage`.
- [x] Set default page size 25 and maximum 100; validate filters and document fixed stable ordering for INV-13.
- [x] Exclude unbounded serialized-unit collections from summaries/detail.
- [x] Include quantities, versions, location active/sellable state, and required movement provenance.
- [x] Statically review each contract separately.
- [x] Obtain review acceptance.

**Supporting contracts:** `InventoryPageFilter` also handles history pagination. `InventoryReadPage` shares typed row validation and flat pagination metadata. `InventoryItemSummary`, `InventoryLocationBalance`, `InventorySerializedItemDetail`, and `InventoryMovementDetail` provide typed rows without lazy database reads. Summary availability is sellable availability; per-location availability is unreserved physical stock, with active/sellable flags supplied separately. Measurement-unit options remain separate. Stable ordering will be implemented in INV-13: item name/ID, unit ID, and movement occurrence time/ID. No existing endpoint or caller uses these contracts yet.

## Repository steps

### INV-09 — Tenant-scoped/version-aware lookups

**Depends on:** INV-03–08 as required by each lookup.

**Files:** Individual methods in `app/Repository/InventoryRepository.php`.

- [x] Review item-by-code lookup and optional locking.
- [x] Obtain acceptance for item-by-code lookup (INV-09a).
- [x] Review location-by-code lookup and shared/exclusive locking support.
- [x] Obtain acceptance for location-by-code lookup (INV-09b).
- [x] Review reservation/unit lookups with explicit tenant/item binding.
    - [x] Add reservation lookup expected-item binding using TenantContext (INV-09c).
    - [x] Obtain acceptance for reservation lookup (INV-09c).
    - [x] Review serialized-unit lookup tenant/item binding (INV-09d, included with INV-12).
    - [x] Obtain acceptance for serialized-unit lookup (INV-09d, accepted with INV-12).
- [x] Add conditional version updates and affected-row conflict handling.
- [x] Preserve unique constraints and review explicit pre-write guards with existing domain exceptions at the service boundary.
- [x] Review the remaining version-write and write-guard methods and obtain acceptance.

**INV-09 remaining write implementation:**

1. Location edits retain the existing exclusive lookup lock and early request-version check.
2. Repository location updates require an active transaction, allow only editable fields, and match the tenant-scoped ID plus expected update_key in SQL.
3. A successful edit advances update_key, including edits with unchanged values; exactly one affected row indicates success. The caller's model is synchronized only on success.
4. Item version increments also match the model's expected update_key in SQL. Successful increments synchronize the model for repeated mutations in the same transaction.
5. Zero affected rows cause the service to throw the existing AlreadyUpdatedException; the owning transaction rolls back. Missing resources are still rejected by named lookup errors before writing.
6. Immediate location-name and serialized-identifier guards require a transaction and use current locking reads with TenantContext and database collation. Rename checks exclude the current location; null serialized identifiers remain allowed and identifier "0" is preserved.
7. Existing DuplicateValueFound messages and database unique constraints are retained. No SQL-error translation was added. Missing-value gap locking depends on database isolation; simultaneous new duplicate values can still cause deadlocks or constraint conflicts. Ordered locking and concurrent retry handling remain in INV-15/18, with MySQL verification in INV-29.
8. Existing guard and version-write call sites were inspected statically. No tests, migrations, jobs, or database operations were run.

**Files changed:** app/Repository/InventoryRepository.php, app/Services/TenantModule/InventoryService.php, and this checklist. User reviewed and accepted these remaining changes; runtime and concurrency verification remain pending in INV-29.
**INV-09a — item lookup:** Reused `findItemByCode(...)`; no query behavior or signature change was needed. Documented explicit tenant/code filtering, the existing unique index, full-model/version return, nullable result, and caller-owned transaction requirement for `FOR UPDATE`. No version comparison or increment is performed by a lookup. Static review only; runtime locking verification remains pending in INV-29. Next review unit: location lookup shared/exclusive locking.

**INV-09b — location lookup:** Extended the existing `findLocationByCode(...)` with optional `sharedLock`. Default reads remain unlocked; existing third-argument `true` still requests an exclusive lock. `sharedLock: true` requests a shared lock when the exclusive flag is false. Both flags true use the stronger exclusive lock. Tenant/code filtering and nullable full-model return are retained. Locks require the caller's transaction; shared-lock caller wiring and ordered multi-location locking remain in INV-15. Static review only; MySQL concurrency verification remains pending in INV-29.

**Current tenant scoping revision:** At the user's request, both INV-09 lookups now rely on the existing `BelongToTenant` global scope backed by `TenantContext`. Removed their explicit tenant-ID parameters and duplicate tenant predicates; updated all current service call sites. Both lookups throw the existing `TenantNotFound` exception before querying if context is absent. Current signatures are `findItemByCode(string $code, bool $lock = false)` and `findLocationByCode(string $code, bool $lock = false, bool $sharedLock = false)`. Thus the exclusive lock flag is now the second argument. The earlier descriptions/log rows record the original implementation; this revision supersedes their explicit tenant argument details. Other repository methods remain for their own review units.

**INV-09c — reservation lookup:** Reused `reservationByCode(string $code, bool $lock = false, ?int $inventoryItemId = null)`. The existing model global scope uses TenantContext; missing context throws TenantNotFound before querying. The optional expected item ID adds an item predicate, returning null on mismatch. Status/location/source/remaining-quantity validation remains owned by services. All four existing callers use the new context-based signature; expected-item filtering will be wired with explicit missing/mismatched reservation rejection in INV-16, avoiding premature changes to existing null fallthrough behavior. Code-only lookup remains available for release flows. Static review only; lock ordering and MySQL verification remain in INV-15/29.

### INV-10 — Optimized movement balance queries

**Depends on:** INV-02, INV-05, INV-09.

**Files:** Individual repository balance methods.

- [x] Add grouped incoming/outgoing balances for a page of item IDs and relevant locations.
- [x] Keep decimal values exact and provide conversion to quantity units, including zero.
- [x] Separate browse aggregates from authoritative write-side current reads.
- [x] Include the approved monthly checkpoints; add no continuously updated balance table or application-wide isolation change.
- [x] Statically review zero-stock, exact-exhaustion, and multiple-location query paths.
- [x] Obtain review acceptance; execute scenarios in INV-29 when authorized.

**Implemented:** `movementBalancesForItems(...)` now groups the latest completed monthly opening base with uncovered incoming/outgoing branches using UNION ALL and SQL DECIMAL sums. `balance(...)`, `balances(...)`, and the positive-location filter reuse that calculation. Missing checkpoints fall back to source history. `InventoryQuantity::parseBalance(...)` converts non-negative stored totals to integer thousandths. Exact exhaustion stays zero; invalid negative/oversized balances are not silently clamped. `movementRowsForUpdate(...)` now returns uncovered current rows only: future authoritative callers must add `checkpointBalancesForItems(...)` under the reviewed item-lock/read protocol. Full lock ordering and strict mutation calculation wiring remain in INV-15/16. Monthly implementation and pending verification are documented in the Monthly inventory checkpoints section below.

### INV-11 — Reservation queries

**Depends on:** INV-05, INV-09.

**Files:** Individual repository reservation methods.

- [x] Batch active remaining quantities by item/location.
- [x] Batch active reserved-unit membership.
- [x] Provide current locking reads for mutation decisions.
- [x] Avoid repeated per-unit/per-location queries in the new batch methods.
- [x] Statically review tenant/state filtering.
- [x] Obtain review acceptance.

**Implemented:** `reservationBalancesForItems(...)` returns exact active remaining totals by item/location. `activeReservationsForItems(...)` fetches decoded unit membership and remaining quantities together; optional locking performs a current read in ID order and requires the caller's transaction and previously locked anchors. Both use TenantContext. Empty item/location filters match no rows; released/consumed reservations are excluded. Existing per-item callers will switch in later service/caller steps.

### INV-12 — Unit custody and identifier queries

**Depends on:** INV-05, INV-09.

**Files:** Individual repository unit methods.

- [x] Batch latest movement IDs per unit and join custody/location records.
- [x] Batch tenant-scoped identifier existence lookup.
- [x] Request selected-unit locks in ascending ID order.
- [x] Preserve identifiers such as `"0"`; handle trimming, nullable identifiers, and database uniqueness consistently.
- [x] Statically review each query.
- [x] Obtain review acceptance; verify actual MySQL locking/query plans in INV-29.

**Implemented:** `latestMovementsForSerializedItems(...)` joins MAX movement IDs per requested unit and eager-loads locations. `existingSerializedItemIdentifiers(...)` checks the identifier batch using database collation. `findSerializedItemsByCodesForUpdate(int $itemId, array $unitCodes)` now uses TenantContext, requires a transaction, binds to the expected item, and orders by ID. Its two existing service callers were updated. Missing/wrong-item codes return fewer rows for complete-set validation. Browse custody snapshots must not be used as authoritative mutation reads; current movement rows are available from INV-10.

### INV-13 — Paginated repository reads

**Depends on:** INV-08, INV-10–12.

**Files:** Individual repository item/history/unit read methods.

- [x] Implement paginated item summary retrieval without loading every unit.
- [x] Implement paginated history ordered by occurrence time and ID.
- [x] Implement paginated serialized units with location/availability filters.
- [x] Batch related Catalog/unit/location reads.
- [x] Provide access beyond the former 100-item/500-movement caps in the new page methods.
- [x] Statically review each read independently.
- [x] Obtain review acceptance.

**Implemented:** `itemPage(...)` supports text/tracking/location filters, eager-loads Catalog and measurement-unit references, and sorts by name/ID. Its location filter checks positive net custody, not merely historical movement presence. `movementPage(...)` sorts by occurrence time/ID and eager-loads locations, serialized identity, and actor using new `InventoryMovement::inventoryUnit()` / `createdBy()` relations. `serializedItemPage(...)` joins latest custody and location attributes and uses an active reservation membership subquery for filtering. Identifier/code search preserves `"0"`. Availability means physically present and unreserved; active/sellable flags remain separate. Production MySQL JSON_CONTAINS is used for reservation JSON membership. All new reads use TenantContext and the existing 25/100 pagination filters. Endpoint and DTO construction wiring remain in INV-21/26; the old endpoints still have their existing caps until replaced.

## Service dependency steps

### INV-14 — Quantity, tracking, and reference validation

**Depends on:** INV-02, INV-07, INV-09.

**Files:** Individual helpers in `app/Services/TenantModule/InventoryService.php`; Inventory message additions where required.

- [x] Use the exact quantity helper for internal and public input validation.
- [x] Validate tracking mode, unit count, distinct units, and tenant references.
- [x] Permit freeform serialized goods with a valid unit and optional Catalog reference.
- [x] Provide active destination and operation-specific sellable-location validation.
- [x] Guard duplicate location names and serialized identifiers before writes using DuplicateValueFound and localized messages.
- [x] Statically review each helper.
- [x] Obtain review acceptance.

**Implemented:** `validateQuantityForMode(...)` returns a normalized exact decimal string. `validateStockCommandQuantity(...)` returns integer thousandths for typed command callers, checks the supplied item's tenant/public reference, and reuses `validateSerializedItemSelection(...)` and `validateExistingReceiptReferences(...)`. Receipt metadata conflicts are checked through existing Catalog services. `validateSerializedReceive(...)` checks counts exactly, rejects non-string/oversized identifiers, trims whitespace, retains `"0"`, excludes nulls explicitly, and uses one batch existence query. `createInventoryItem(...)` now accepts freeform serialized goods with a valid measurement unit, validates quantity before inserting, and rejects empty/oversized names. `activeLocationByCode(...)` supports an operation-specific sellability requirement; authoritative checks and sale caller activation remain in INV-15/16. `translateInventoryWriteException(...)` was removed at user request. Shared location-name guards run before create/rename, and serialized receipt/adjustment checks identifiers both in a batch and immediately before each insert using database collation. DuplicateValueFound supplies localized conflicts. Missing Inventory resources use TenantNotFound with named item/location messages. Database constraints remain authoritative for concurrent races; prechecks alone are not concurrency serialization. INV-15/18 must review the remaining race behavior without reinstating an SQL-error translator as routine validation. English/Myanmar messages were added with matching keys.

**Remaining integration boundary:** These are dependency queries/helpers. New paginated/batch reads are not yet public endpoints. The current orchestration still has legacy float arithmetic/tolerance, per-unit custody reads, and reservation fallthrough behavior; their replacements remain in INV-16/17/19–28. The current validation changes do not claim complete stock/concurrency correctness. No tests, migrations, query plans, or database operations were executed.

### INV-15 — Ordered pessimistic locking

**Depends on:** INV-09–12.

**Files:** Individual repository/service locking helpers.

- [x] Lock existing item anchors in ascending ID order.
- [x] Lock locations in ascending ID order: shared for stock operations, exclusive for location edits.
- [x] Lock reservations, then selected serialized units, in ascending ID order.
- [x] Recheck bindings/state after acquiring locks.
- [x] Keep locks until the owning transaction completes.
- [x] Establish multi-item caller prelocking requirements.
- [x] Use authoritative current reads; do not rely on stale nonlocking snapshots.
- [x] Review receive/move/issue/release ordering and obtain acceptance.

### INV-16 — Availability and reservation validation

**Depends on:** INV-10–12, INV-14–15.

**Files:** Individual Inventory service helpers.

- [x] Compare availability exactly, including requests for `0.001`.
- [x] Require sellable locations for sale reservations/fulfilment.
- [x] Reject missing supplied reservations rather than treating them as absent.
- [x] Validate tenant, item, location, source, status, and remaining quantity.
- [x] Validate exact serialized membership and current custody.
- [x] Keep the future Packing/collateral restriction contract explicit without implementing those modules now.
- [x] Review each helper and obtain acceptance.


#### INV-15/16 implementation and activation boundary

**Batch authorization:** The user requested INV-15 and INV-16 together after confirming helpers first. These dependencies are implemented together for review; main-flow wiring stays in INV-19-28.

**Files:** InventoryRepository, InventoryService, InventoryIssueRequest, new internal InventoryLockedStock, and English/Myanmar Inventory messages. No schema changes.

1. **INV-15a:** findItemsByCodesForUpdate locks the complete tenant-scoped item set in ascending ID order. lockStockForOperation normalizes reference lists and rejects incomplete sets.
2. **INV-15b:** findLocationsByCodesForUpdate supports shared stock locks and exclusive edit locks in ascending ID order. Stock preparation acquires all items before locations.
3. **INV-15c:** reservationsForStockOperation locks all active item reservations plus explicitly supplied inactive records in ID order. serializedItemsForStockOperation then locks the selected identities across the complete item set in ID order. Missing supplied reservations/identities are rejected.
4. **INV-15d:** currentStockRowsForUpdate selects one latest completed eligible checkpoint per item using an indexed current locking read, then batches its children and uncovered movement rows. Base and tail are pinned to the same headers; absent checkpoints use full history. currentSerializedItemCustodyForUpdate uses a current locking self anti-join instead of a snapshot MAX subquery. Checkpoint selection costs one indexed query per item; balances, reservations, selected identities, and custody are batched.
5. **INV-16a:** calculateLockedStockBalances uses integer thousandths for checkpoint base plus signed deltas. It rejects integer overflow, negative final stock, and unsupported quantity ranges. validateLockedStockAvailability subtracts exact live reservations and credits only a validated own reservation; no float tolerance.
6. **INV-16b:** validateLockedIssueReservation rejects missing/inactive/mismatched/insufficient reservations. InventoryIssueRequest adds trusted expectedReservationSourceType and expectedReservationSourceCode separately from issue provenance. Sale issue requires a reservation; source fields must match its original business source. Public factories cannot set these trusted fields. Sale reservations and fulfilment require active, sellable locations.
7. **INV-16c:** selected-code shape validation is reused. Current locked identity/custody and reservation membership are checked without per-unit queries. Duplicate serialized reservation membership and remaining quantity/code-count inconsistencies are rejected. Membership maps avoid repeated linear searches. Move validation checks both locked locations and the existing expected version.
8. **Caller contract:** invoke lockStockForOperation once inside an already active business transaction with all item/location/reservation/serialized references before processing lines. Hold anchors until the outer transaction ends. The returned internal InventoryLockedStock must not be cached, serialized to HTTP, reused after mutation, or carried into another transaction. Rebuild current data after changes.
9. **Restriction boundary:** Packing and Business Loan collateral checks are not implemented or claimed by these helpers. Their later workflows must integrate authoritative restriction validation under the same locking protocol.
10. **Activation boundary:** existing receive/move/issue/reserve/release orchestration is not switched to these helpers. Sales must supply the originating SaleOrderLine reference separately from SaleDeliveryAllocation provenance when caller integration is implemented. Existing float/tolerance and reservation fallthrough behavior remain until INV-19-28 activation.
11. **Verification:** PHP syntax checks passed for the six affected PHP files. No tests, migrations, jobs, database queries, or query plans were run. MySQL current-read, lock-order, deadlock, exact-shortfall, reservation binding, and checkpoint equivalence scenarios remain in INV-29.

**Sparse balance optimization:** calculateLockedStockBalances no longer allocates every selected item/location combination. It builds item buckets and a location lookup set, populates observed checkpoint/movement pairs, and validates each stored pair once. Time is O(I + L + C + B + M + P), where P is observed pairs; extra space is O(I + L + C + P), with no second per-movement copy or flat balance matrix. Missing pairs are implicit zero, matching the existing availability consumer's null-coalescing lookup. Same-location movements, signed intermediate deltas, final negative checks, and integer overflow/range checks are preserved. Syntax checks only; runtime verification remains pending.

**Readability refinement:** lockStockForOperation now orchestrates normalization, complete-set locking, and snapshot construction through smaller private helpers. validateLockedStockAvailability orchestrates transaction/binding checks, operation/location/source rules, reservation validation, exact reserved totals, quantity comparison, and serialized custody checks. Existing repository queries, lock order, exception behavior, public signatures, and main-flow activation boundaries are preserved. PHP syntax validation passed; no tests or database operations were run.

- [x] Implement INV-15a-d and INV-16a-c as reusable dependency helpers.
- [x] Obtain user acceptance of the INV-15/16 helper batch, including readability and sparse-balance refinements.
- [ ] Activate helpers in INV-19-28 and verify runtime/concurrency behavior in INV-29.

### INV-17 — Movement/reservation mutation helpers

**Depends on:** INV-07, INV-14–16.

**Files:** Individual Inventory repository/service helpers; reservation/movement models only where needed.

- [x] Append movements with actor, timestamp, source, unit, and idempotency references.
- [x] Prevent application-level rewriting/deletion of movement history.
- [x] Increment item versions for physical/reservation changes (wired and accepted with INV-DTO-01; runtime verification pending).
- [x] Remove exact consumed serialized codes during issue.
- [x] Require exact unit codes for partial serialized release; support full release of all remaining codes.
- [x] Keep serialized remaining quantity equal to remaining unit-code count.
- [x] Return only affected movement/unit codes.
- [x] Review each mutation helper and obtain acceptance.

### INV-18 — Inventory transaction, idempotency, and audit wrapper

**Depends on:** Completed contracts/helpers; existing idempotency/audit services.

**Files:** Inventory operation wrapper and required Inventory messages.

- [x] Reserve and complete Inventory idempotency with the business transaction.
- [x] Preserve same-key replay and different-payload conflict semantics.
- [x] Avoid committing a standalone processing record before stock work starts.
- [x] Bound recognized deadlock retries to the outermost operation, maximum three attempts.
- [x] Do not retry validation, version conflicts, or arbitrary failures.
- [x] Keep public Inventory audit atomic; avoid duplicate primary audits for internal caller operations.
- [x] Keep failure diagnostics and post-commit cache/notification responsibilities explicit.
- [x] Review wrapper behavior and obtain acceptance.


#### INV-17/18 implementation and activation boundary

**Batch authorization:** After accepting INV-15/16 and its refactors, the user requested INV-17/18 together. Orchestration methods call small named phase helpers in the same style as INV-15/16.

1. **INV-17a - append and results:** appendMovement accepts exact decimal text, normalizes through InventoryQuantity, resolves the actor using the tenantuser guard, retains source/timestamp/idempotency metadata and existing conditional version increments, and returns the created movement. buildStockMutationResult copies operation-local movement codes and selected serialized codes using the existing response DTO; no full item history/unit collection is loaded.
2. **INV-17b - immutability:** InventoryMovement rejects persisted updates and instance deletes, including quiet paths. Its dedicated query builder also rejects model-derived bulk updates/deletes, increments/decrements, truncation, and upserts. Inserts and reads remain available. Direct DB::table/raw SQL and database cascades are outside these application guards; no database triggers or migrations were added.
3. **INV-17c - consumption:** consumeLockedStockReservation reuses locked availability/source/membership validation, subtracts exact quantity, removes exactly the consumed codes, validates remaining code-count equality, and persists ACTIVE or CONSUMED at exact zero. It must run before issue appends within the same transaction; those existing physical movement increments cover the issue version. Rebuild/discard the snapshot after changes.
4. **INV-17d - release:** InventoryReservationReleaseRequest binds tenant-resolved item/location/reservation and originating source. Null quantity plus no codes means full release; selected serialized release requires explicit codes and matching quantity/count. releaseLockedStockReservation validates binding/state, subtracts exact membership/quantity, sets RELEASED only at zero, and advances the item version for a real change. Repeated full release of a consistent zero RELEASED/CONSUMED record performs no write or increment.
5. **INV-18a - atomic wrapper:** runStockOperation and its per-attempt helper reserve optional idempotency inside the same business transaction as callback writes, public audit, and completion. Existing hash/replay/different-payload semantics are reused. Rollback removes a newly reserved processing record; the new wrapper does not write a separate failed record afterward.
6. **INV-18b - retry ownership:** Inventory-owned outer transactions get at most three total attempts, only for MySQL deadlock 1213 found in the exception chain. Caller-owned transactions get one attempt and propagate failures to their owner. Lock timeouts, validation, stale versions, duplicate values, and idempotency conflicts are not retried. Callbacks must reacquire attempt-local models and perform no external side effects.
7. **INV-18c - audit ownership:** trusted InventoryOperationContext plus InventoryOperationOwner identify public Inventory versus business-caller operations. Public success audit is atomic with stock/idempotency; business callers keep their own primary audits. Replay bypasses callback/audit and is not logged as failure. Only final failures go through OperationLogger; diagnostic channel failures cannot mask the original exception.
8. **After-commit responsibilities:** callers must use the existing outer transaction's afterCommit mechanism for cache invalidation, notifications, and other external side effects. The dependency wrapper does not introduce a notification/cache subsystem.
9. **Activation boundary:** new consumption/release/result/operation helpers are not wired into existing main flows. Current runIdempotent, legacy reservation arithmetic, endpoint response arrays, and caller release signatures remain until INV-19-28. The low-level append dependency and movement history guards apply now. No claim is made that existing endpoints already use the new transaction/audit protocol.
10. **Verification:** PHP syntax checks only; no tests, migrations, jobs, or database operations. MySQL runtime checks for rollback, concurrent keys, deadlock retries, serialized consumption/release, append-only paths, actor provenance, and audit ownership remain in INV-29.

**Files:** InventoryService, InventoryMovement, new InventoryMovementQueryBuilder, InventoryReservationReleaseRequest, InventoryOperationContext, InventoryOperationOwner, English/Myanmar Inventory messages, and this checklist.

- [x] Implement INV-17a-d and INV-18a-c dependencies.
- [x] Obtain user review acceptance of INV-17/18.
- [ ] Wire main flows in INV-19-28 and execute authorized INV-29 verification.

## Orchestration steps — implement only after dependencies are accepted

### INV-19 — Create location

**Depends on:** INV-06, INV-09, INV-18; existing code generator.

**Files:** `InventoryService::createLocation(...)` and its immediate integration only.

- [x] Normalize/validate name, type, and sellability.
- [x] Generate code and persist inside a transaction.
- [x] Handle concurrent duplicate names through domain errors.
- [x] Write location audit and idempotency result atomically.
- [x] Obtain review acceptance.


**Implemented INV-19 workflow:**

1. InventoryController::storeLocation reuses the existing mutate boundary to validate name, type, sellability, and the optional Idempotency-Key header, then constructs InventoryLocationCreate.
2. InventoryService::createLocation validates the normalized type for internal DTO callers and builds the trusted public-operation/audit context.
3. The existing runStockOperation wrapper now accepts InventoryLocationCreate. It starts the owning transaction before reserving idempotency; replay exits before duplicate guards, code allocation, creation, or audit.
4. lockLocationCreationNamespace locks the tenant's existing default location through TenantContext. Tenant migration/bootstrap already provides this row; a missing row raises the named location exception instead of silently bootstrapping another location.
5. assertLocationNameAvailable validates the trimmed name and performs the existing current database-collation duplicate check. Concurrent createLocation calls for the same tenant serialize on the default anchor; unique name/code constraints remain intact. Location-rename coordination remains in INV-20.
6. persistNewInventoryLocation allocates the public code and inserts explicit is_default=false, is_active=true, selected sellability (including false), and update_key=0 inside the same transaction.
7. InventoryLocationDetail copies the created scalar attributes and serializes the existing public response fields. No frontend payload or response field changes are required.
8. The wrapper records inventory.location.created with InventoryLocation target type and the created public code/state in result metadata, completes the idempotency response with HTTP 201, and commits atomically. No cache or notification side effects are introduced.
9. Only an Inventory-owned outer operation retries recognized MySQL deadlocks, at most three total attempts. Nested callers retain ownership of retries; all writes/code allocation/audit/idempotency participate in their transaction.
10. PHP syntax checks only. Runtime scenarios remain pending: normalized/invalid names and types, false sellability, missing bootstrap location, collation-equivalent duplicates, simultaneous duplicate creates, same-key replay, changed-payload conflict, rollback after audit/completion failure, and caller-owned rollback. Actual lock footprint and query plans require the prepared MySQL verification in INV-29.

**Files:** InventoryService, InventoryRepository, InventoryController, English/Myanmar Inventory messages, and this checklist. No migration or frontend changes.

### INV-20 — Update location

**Depends on:** INV-03, INV-06, INV-09–11, INV-15, INV-18.

**Files:** `InventoryService::updateLocationByCode(...)`.

- [x] Acquire exclusive location lock and compare expected `update_key` (INV-DTO-01).
- [x] Reject stale edits with HTTP 409 using AlreadyUpdatedException (runtime verification pending).
- [x] Keep default location active.
- [x] Require zero stock and no active reservations before deactivation.
- [x] Validate restriction changes against active operation requirements.
- [x] Increment version, record audit, and complete the transaction.
- [x] Obtain review acceptance.


**Implemented INV-20 workflow:**

1. InventoryController::updateLocation reuses the existing mutate boundary for partial-edit validation and optional Idempotency-Key headers. Route location code and expected version remain in InventoryLocationUpdate.
2. updateLocationByCode validates an explicitly supplied type through the shared validateLocationType helper and configures the public update audit.
3. runStockOperation reserves/replays idempotency inside the owning transaction. Replays exit before stale-version validation, so a successful repeated request returns its original result without another edit or audit.
4. Explicit name edits acquire the existing default-location namespace anchor before the target location lock, coordinating duplicate names with INV-19 creation. Non-name edits acquire only their target edit lock.
5. lockLocationForEdit reuses the tenant-scoped exclusive lookup and existing AlreadyUpdatedException version comparison.
6. validateLocationEdit reuses the database-collation duplicate guard with current-location exclusion, prevents default deactivation, and checks active reservations before deactivation or disabling sellability.
7. Deactivation verifies per-item net stock from one current locking canonical-history query scoped to the location. Negative totals are rejected as inconsistent; positive stock blocks deactivation. Quantities use integer thousandths and existing overflow checks. This infrequent administrative check intentionally reads full source history; monthly checkpoint-backed browse and ordinary stock availability paths remain unchanged. Representative query cost/lock footprint remain pending in INV-29/30.
8. Type changes are descriptive under current implementation; active/sellable flags are the enforced operational restrictions. Packing/loan-collateral restrictions remain owned by their later workflows. Shared/exclusive location locks coordinate already-integrated stock callers; remaining writer activation stays in INV-22-28.
9. persistLocationEdit preserves explicit false and ignores null fields, then reuses the conditional expected-version repository update and its affected-row conflict handling.
10. InventoryLocationDetail serializes the updated public fields. The wrapper atomically records inventory.location.updated, completes the HTTP 200 idempotency result, and commits. There are no new cache/notification side effects.
11. Syntax checks only. Pending scenarios: stale/missing/foreign locations, invalid internal type, unchanged and duplicate names, rename/create races, default protection, empty/no-history and exhausted stock, quantity 0.001, separate-item cancellation, active reservations, sellability changes, replay/payload conflicts, rollback, and concurrent stock/location edits.

**Files:** InventoryService, InventoryRepository, InventoryController, English/Myanmar messages, and this checklist. No schema/frontend changes; runtime/concurrency verification remains pending.

### INV-21 — Read services

**Depends on:** INV-08, INV-13; completed response construction helpers.

**Files:** Individual Inventory read-service functions.

- [x] Implement item summary page service.
- [x] Implement item detail service.
- [x] Implement movement history page service.
- [x] Implement serialized-unit page service.
- [x] Preserve measurement-unit options as a separate endpoint/service meaning.
- [x] Confirm no per-item/unit query loops; obtain acceptance for each function.


### INV-22 — Move stock

**Depends on:** INV-07, INV-14–18.

**Files:** `InventoryService::moveStock(...)`.

- [x] Validate public item version/idempotency and distinct locations.
- [x] Acquire ordered locks and recheck current stock, unit custody, and restrictions.
- [x] Reject stale client state with 409 and insufficient quantity without side effects.
- [x] Append move effects, conserve stock, increment version, and write Inventory audit.
- [x] Commit and return mutation result; obtain review acceptance.


### INV-23 — Internal receipt

**Depends on:** INV-07, INV-14–18; completed item-creation/Catalog/unit lookup helpers.

**Files:** `InventoryService::receive(...)`; necessary receipt-result consumer wiring kept narrow.

- [x] Create or resolve the item and validate tracking/identifiers.
- [x] Follow the ordered locking protocol.
- [x] Append receipt movements and return only newly received unit codes.
- [x] Increment version and preserve caller-owned financial/audit responsibilities.
- [x] Keep existing Pawn receipt compatibility without implementing redemption.
- [x] Obtain review acceptance.


### INV-24 — Reservation creation and release

**Depends on:** INV-07, INV-15–18.

**Files:** One Inventory reservation orchestration function per review unit.

- [x] Implement sale reservation creation using current sellable availability.
- [x] Implement full reservation release.
- [x] Implement partial quantity release and exact partial serialized release.
- [x] Reject stale bindings/state and keep unit/quantity/version invariants.
- [x] Obtain review acceptance for each function.


#### INV-21-24 orchestration batch

**Authorization:** The user accepted INV-20 and requested INV-21 through INV-24 together, explicitly directing reuse of implemented functions. Existing lock/availability/mutation/transaction helpers are reused; no new context, wrapper, table, or migration is introduced.

1. **INV-21 summaries:** itemSummaryPage calls itemPage, grouped checkpoint-backed movement balances, grouped active reservation quantities, and one location lookup. It builds InventoryItemSummary and InventoryItemListPage without serialized collections or per-item queries. Empty pages skip balance/location queries.
2. **INV-21 detail:** itemDetail resolves Catalog/unit references eagerly through the existing item lookup and builds InventoryItemDetail plus typed per-location balances from the same batch projection. No complete serialized collection is loaded.
3. **INV-21 history and identities:** movementHistoryPage maps existing eager provenance relations to InventoryMovementDetail/InventoryMovementListPage. serializedItemsPage maps the repository's joined custody/reservation projection to InventorySerializedItemDetail/InventorySerializedItemListPage. Measurement units remain separate.
4. **INV-21 activation:** these typed read service functions are ready for INV-26 endpoint wiring. Existing array reads remain for current controllers, Sales, and Ownership until their coordinated INV-26-28 adaptation; this batch does not change their response shapes.
5. **INV-22 move:** moveStock uses runStockOperation, lockStockForOperation, validateLockedStockAvailability, existing appendMovement, and buildStockMutationResult. Locks and current-read/version validation precede movement appends; each move preserves quantity across locations and returns only affected codes. The direct Inventory success audit and idempotency completion are atomic.
6. **INV-23 receipt:** receive uses the atomic wrapper, creates or locks the item before shared destination locks, reuses reference/tracking/identifier guards, and appends exact receipt movements. UNIQUE checks reuse currentStockRowsForUpdate with all locations and the existing sparse balance calculator, avoiding an old location-list snapshot. New serialized identities alone are included in InventoryMutationResult.
7. **INV-23 ownership:** receive accepts explicit audit ownership, defaulting to BusinessCaller; InventoryController supplies PublicInventory. Metadata does not choose audit ownership or permissions. Purchasing receives inventory_item_code and this receipt's serialized_item_codes only, retaining its existing accounting, financial, ownership, and audit workflow.
8. **INV-23 Pawn compatibility:** receivePawnCollateral calls the same receipt orchestration with collateral provenance and a stable custody idempotency key, then returns the existing InventoryItem model to its callers. No ownership acquisition or redemption is added.
9. **INV-24 reserve:** reserveForSale uses ordered stock locks and exact sellable availability, persists exact quantity/code membership via the existing repository, advances the item version, and returns InventoryMutationResult with reservation_code. Sales' immediate result consumer is updated.
10. **INV-24 release:** releaseSaleReservation now accepts InventoryReservationReleaseRequest, prepares ordered locks, and delegates full/partial quantity or explicit serialized release to releaseLockedStockReservation. Existing source/state/count/version invariants and repeat-full-release no-op behavior are reused. Sales cancellation constructs the typed release with its original SaleOrderLine source and stable key.
11. **Narrow client adaptation:** frontend receive/move methods declare the affected-reference mutation result. The existing movement UI already ignores returned bodies; its callback type now reflects that. Pagination, fresh-selection/version refresh, stable retry keys, feature gates, and broader UI wiring remain INV-26/27.
12. **Remaining boundaries:** issueStock, sale returns, and adjustment still use their earlier paths until INV-25/26/28. Multi-item business caller prelocking and complete caller migration remain INV-28. Existing partial issue membership arithmetic is not claimed fixed by this batch. Overall simplification remains deferred to INV-30.
13. **Verification:** PHP syntax/static reference checks only. No tests, migrations, jobs, database operations, or frontend build/typecheck. Pending scenarios include pagination beyond former caps, filter 0, eager-query counts, current custody/reservations, exact 0.001 shortage/exhaustion, stale versions, reversed/concurrent moves, new/existing/freeform/UNIQUE/serialized receipts, duplicate identifiers and rollback, receipt-only identity results, Pawn model compatibility, partial/full/repeated releases, wrong source bindings, and outer rollback/audit/idempotency replay.

**Files:** InventoryService, InventoryRepository, InventoryController, PurchasingService, SalesService, English/Myanmar messages, narrow frontend Inventory types/service/callback typing, and this checklist.

- [x] Implement INV-21 typed read service orchestration.
- [x] Wire INV-22 move orchestration to completed helpers.
- [x] Wire INV-23 receipt orchestration and immediate receipt consumers.
- [x] Wire INV-24 reservation creation/release and immediate Sales consumers.
- [x] Obtain user review acceptance of INV-21-24.
- [ ] Complete later endpoint/caller activation and authorized INV-29 verification.

### INV-25 — Internal issue

**Depends on:** INV-07, INV-14–18, INV-24.

**Files:** `InventoryService::issueStock(...)`.

- [x] Validate supplied reservation and internal source context.
- [x] Recheck availability/custody under locks.
- [x] Issue exact quantity/units without a tolerance-based shortfall.
- [x] Consume reservation quantity/codes consistently and increment version.
- [x] Return affected records; retain caller-owned accounting/account/audit completion.
- [x] Obtain review acceptance.

**INV-25 implementation (2026-10-19):** Activated the existing ordered stock locks, exact availability/custody validation, reservation consumption, versioned mutation result, and atomic operation wrapper in `issueStock`. Supported Sale/PurchaseReturn provenance is checked; deferred Pawn redemption remains unavailable. Both Sales issue callers now provide the original `SaleOrderLine` reservation source. Business callers retain accounting/account/primary-audit ownership; standalone Manual issue owns its Inventory audit. The frontend issue response type is coordinated with the mutation result. PHP syntax and diff whitespace checks passed; runtime/concurrency verification, tests, and migrations were not executed.

### INV-ADJ-01 — Adjustment orchestration amendment

Requested after INV-25 acceptance to close the previously recorded adjustment orchestration gap.

- [x] Use the shared atomic stock-operation wrapper for transaction, audit, idempotency, and bounded outer retry handling.
- [x] Reuse ordered stock locks and exact outbound availability/reservation/custody checks.
- [x] Reuse inbound tracking/identifier validation and current checkpoint-backed UNIQUE custody checks.
- [x] Reuse receipt/issue append helpers with explicit `ADJUST_IN` / `ADJUST_OUT` movement types and versioned affected-record results.
- [x] Coordinate the frontend adjustment response type with `InventoryMutationResult`.
- [x] PHP syntax, frontend TypeScript, and diff whitespace checks; no tests, migrations, or database execution.
- [ ] Obtain review acceptance.
- [ ] Runtime/concurrency verification in INV-29 when authorized.

## Public integration steps

### INV-26 — Controller endpoints and feature gates

**Depends on:** The completed service function for each endpoint.

**Files:** Individual `InventoryController` actions, `routes/api.php`, feature configuration/migration as separate review units.

- [ ] Register inactive `inventory_manual_operations` consistently.
- [ ] Wire paginated item/detail/history/unit contracts one endpoint at a time.
- [ ] Keep `/inventory/measurement-units` as measurement-unit options; use an item-scoped route for serialized units.
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
- [ ] Monthly checkpoints: verify full-ledger equivalence, checkpoint-plus-tail quantities, month boundaries, below-watermark new-month rows, late/backdated writes, append/summary locks, duplicate jobs, rollback/resume, tenant context restoration, and scheduler/worker behavior described in [Monthly inventory checkpoints](#monthly-inventory-checkpoints).
- [ ] Gates: direct calls to disabled standalone operations produce no side effects.
- [ ] Record results and unresolved findings; obtain final Part 1 review acceptance.

Concurrency verification requires independent MySQL connections and a separately prepared disposable schema. Do not run migrations as part of verification. SQLite alone is insufficient for locking claims.

## INV-30 - Overall implementation reanalysis and refactoring

**Depends on:** Completed INV-01 through INV-29, including all orchestration, endpoints, request/response contracts, and business-caller wiring. Perform this review after the complete implementation is available; do not interrupt the remaining development sequence to perform the broad refactor now.

**Purpose:** Reassess the extensive operations, locking, transaction handling, and validation checks across the finished Inventory implementation. Compare the actual integrated workflows with current PawnRedemptionService, PawnInterestProcessService, and their called services/repositories. Existing Pawn patterns are comparison references, not assumed proof of concurrency correctness.

- [ ] Confirm all planned methods/functions are wired and remaining dependency-only or superseded paths are identified.
- [ ] Trace each complete workflow and record its transaction owner, item/location/reservation/serialized locks, current reads, validation phases, version writes, idempotency, and audit ownership.
- [ ] Compare these flows with existing Pawn workflows, identifying genuine domain differences and avoidable implementation complexity.
- [ ] Identify repeated lookups/locks, excessive lock scope, duplicated validation, redundant version checks, unnecessary transaction nesting, and parallel old/new wrappers.
- [ ] For each proposed removal or simplification, document the invariant protected, all affected writers, database isolation/current-read requirements, and caller impact.
- [ ] Prepare small dependency-first refactor units with specific files/functions and expected behavior; preserve readable orchestration through named phase helpers.
- [ ] Apply the reviewed refactors while preserving exact quantities, tenant isolation, stock/reservation/custody correctness, atomic audit/idempotency, and bounded outer retry ownership.
- [ ] Update INV-29 verification scenarios for the final simplified flows and execute checks only when authorized. Keep unperformed runtime/concurrency/performance verification pending.
- [ ] Record retained safeguards and their reasons, removed duplication, remaining risks, and user review acceptance.

No automatic global isolation changes, business-scope expansion, or unrelated Pawn refactoring is authorized by this checkpoint.

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

| 2026-10-18 | INV-09d dependency | `InventoryRepository::findSerializedItemsByCodesForUpdate`, two service callers | Implemented with INV-12; user review pending | Static review of context scope, expected-item predicate, transaction requirement, and ID ordering. No execution | Remaining INV-09 version writes |
| 2026-10-18 | INV-10 | `movementBalancesForItems`, `movementRowsForUpdate`, `InventoryQuantity::parseBalance` | Implemented; user review pending | Static review of incoming/outgoing DECIMAL sums, zero/exhaustion paths, location filters, and separate current row reads. No database queries or tests | INV-15/16/21 |
| 2026-10-18 | INV-11 | `reservationBalancesForItems`, `activeReservationsForItems` | Implemented; user review pending | Static review of active status, tenant/item/location filters, batched decoded membership, and optional locking. No execution | INV-15/16/21 |
| 2026-10-18 | INV-12 | `latestMovementsForSerializedItems`, `latestSerializedItemMovementIds`, `existingSerializedItemIdentifiers`, selected-unit lookup | Implemented; user review pending | Static review of latest-ID grouping, tenant-scoped joins, identifier collation boundary, and ordered locks. MySQL plans/concurrency pending | INV-15/16/21 |
| 2026-10-18 | INV-13 | `itemPage`, `movementPage`, `serializedItemPage`, two movement model relationships | Implemented; user review pending | Static review of stable pagination, batched relationships, location net stock, JSON membership, and no old hard caps in new methods. Endpoints unchanged; no execution | INV-21/26 |
| 2026-10-18 | INV-14 | Inventory service quantity/reference/unit-selection/location validators, uniqueness translator, English/Myanmar messages | Implemented; user review pending | Static review of exact input validation, freeform serialized goods, identifier 0/null/whitespace, typed receipt references, and known-only constraint translation. Later wrapper/orchestration wiring recorded above; no execution | INV-15/16/18/23 |

| 2026-10-18 | Inventory terminology | Renamed physical model/read contracts, stock DTO properties, repository/service helpers, history relationship, and frontend type/labels; clarified measurement option methods | Implemented per user instruction; review pending | Static reference review; existing schema and active API field mappings preserved. No tests or migrations run | Continue pending Inventory review |

| 2026-10-18 | Remove physical-item compatibility mappings | Inventory/Sales/Purchasing/Delivery schema, models, repositories, services, controller validation, code generation, DTOs, frontend payloads, response fields, and measurement route | Implemented per user instruction; review pending | Static reference scan found no old physical table/field/class names or old measurement URL in source/config/schema/frontend. Fresh-schema definitions updated, explicit model table override removed, code prefix changed to SI; no migrations, database operations, or tests run | Continue pending Inventory review |

| 2026-10-18 | Inventory monthly scope amendment / INV-MS-01–07 | Checkpoint migration/model, InventoryRepository, InventoryMonthlySummaryService, scheduled job, recovery command, scheduler registration, messages, module plan, and monthly detail plan | Implemented; user review pending | Static review of base/delta predicates, recording cutoff, item anchor, atomic publication, context restoration, duplicate skips, tenant queue dispatch, and schedule registration. No tests, migrations, jobs, or database operations run | Review monthly scope and implementation; then pending INV-09/15–18 integration |

| 2026-10-18 | Inventory explicit write guards and resource errors | InventoryService, InventoryRepository, English/Myanmar messages, shared implementation rules | Implemented per user instruction; review pending | Removed SQL-error translator; added shared name guards and immediate serialized insert checks with DuplicateValueFound. Replaced Inventory aborts and movement firstOrFail with service-level named TenantNotFound messages. Database unique constraints retained; concurrent race serialization remains in lock review. No tests or migrations run | Review changes, then pending Inventory dependencies |

| 2026-10-18 | Explicit stock-operation names | Inventory service/controller, Sales/Purchasing callers, route actions, frontend service/page, and checklist | Implemented per user instruction | Static reference scan confirms old bare operation calls are removed. No tests run | Continue pending Inventory review |

| 2026-10-18 | INV-DTO-01 | Inventory request DTOs, service/controller, repository version helpers, Sales/Purchasing/returns callers, monthly item-ID lookup caller, and frontend move versions | Implemented; user review pending | No generic operation array inputs remain in InventoryService. PHP syntax checks passed for 13 changed backend files; runtime, concurrency, tests, and database verification not performed. DTO usage and response-contract limitations documented | Review DTO integration; continue remaining Inventory checklist dependencies |

| 2026-10-18 | INV-09d, INV-10–14, INV-DTO-01 | Lookup dependency, repository queries, validation helpers, and typed request integration | User review accepted; runtime verification pending | Acceptance recorded per user instruction. Consolidated the DTO usage and monthly summarizer documents into this checklist with their full detail; monthly implementation review and unexecuted verification remain pending | Finish INV-09 conditional version writes and write-guard review |

| 2026-10-18 | INV-09 completion | Tenant/version-aware lookups | User accepted as done | Lookup substeps accepted; existing locked version checks and increments acknowledged. Remaining mutation conflict handling and concurrent write-guard work stay assigned to INV-15–18/20/22; runtime verification remains in INV-29 | Continue INV-15 |

| 2026-10-18 | INV-09 status correction | Conditional version updates and write guards | Reopened per user correction | Restored both remaining items as unfinished; INV-09a–09d lookup acceptance is retained. This supersedes the preceding INV-09 completion entry | Complete and review the two remaining INV-09 items |

| 2026-10-18 | INV-09 remaining writes | InventoryRepository, InventoryService, checklist | Implemented; awaiting user review | Added tenant-scoped expected-version predicates, affected-row conflict exceptions, and transactional current duplicate guards. Unique constraints retained; concurrency verification and broader retry handling remain pending | Review remaining INV-09 changes |

| 2026-10-18 | INV-09 remaining writes acceptance | InventoryRepository, InventoryService, checklist | Reviewed and accepted by user | All INV-09 implementation and review items accepted; no additional execution. Runtime and concurrency verification remain pending in INV-29 | INV-15 — Ordered pessimistic locking |

| 2026-10-18 | INV-15/16 dependency batch | Inventory repository/service, issue DTO, internal locked-stock contract, English/Myanmar messages | Implemented; user review pending | Ordered item/location/reservation/identity locks, pinned current checkpoint/tail reads, exact availability and source/custody validation. Syntax checks passed; no tests or database execution | Review helper batch; activation remains INV-19-28 |

| 2026-10-18 | INV-15/16 acceptance and INV-17/18 dependency batch | Inventory helpers/contracts, movement model/query guard, messages, checklist | INV-15/16 accepted; INV-17/18 implemented awaiting review | Exact reservation mutation/result helpers, append-only history, atomic operation wrapper with explicit audit ownership and bounded outer deadlock retries. Syntax checks only; runtime activation/verification pending | Review INV-17/18; main flows remain INV-19-28 |

| 2026-10-18 | INV-17/18 acceptance; INV-19 | Inventory location creation, controller, repository anchor, wrapper input union, messages | INV-17/18 accepted; INV-19 implemented awaiting review | Location creation now wires typed output, header idempotency, duplicate guard coordination, atomic audit and completion. Syntax checks only; runtime verification pending | Review INV-19; then INV-20 |

| 2026-10-18 | INV-19 acceptance; INV-20 | Location update orchestration, current restriction queries, controller header wiring, messages | INV-19 accepted; INV-20 implemented awaiting review | Reused optimistic writes and atomic wrapper; added default/stock/reservation restrictions, rename coordination, and typed response. Syntax checks only | Review INV-20; then INV-21 |

| 2026-10-18 | INV-20 acceptance; INV-21-24 batch | Inventory read/move/receipt/reservation orchestration, immediate Purchasing/Sales/Pawn callers, frontend mutation typings, messages | INV-20 accepted; INV-21-24 implemented awaiting review | Reused completed repositories and helpers; typed read services prepared, mutation workflows activated, receipt-only serialized identity results coordinated. Syntax/static checks only | Review INV-21-24; then INV-25 |

| 2026-10-18 | INV-21-24 acceptance | Read services, move, receipt, reservation orchestration, immediate consumers | Reviewed and accepted by user | No additional execution; runtime/concurrency verification remains in INV-29. INV-25 issue work and the unassigned adjustment orchestration gap remain pending | INV-25 |

| 2026-10-19 | INV-25 acceptance | Internal issue orchestration, Sales reservation source binding, frontend issue response typing | Reviewed and accepted by user | PHP syntax, TypeScript, and whitespace checks passed during implementation; runtime/concurrency verification remains pending in INV-29. No additional execution for acceptance | INV-26 |

## DTO usage and contract integration

### Current request contracts

Inventory operation methods now receive request DTOs and access named properties, matching the input style used by Pawn LoanContractServices\ManagementService. There is no generic array $data operation parameter left in InventoryService.

| Contract | InventoryService operation/helper | Caller |
|---|---|---|
| InventoryLocationCreate | createLocation | InventoryController::storeLocation |
| InventoryLocationUpdate | updateLocationByCode | InventoryController::updateLocation |
| InventoryReceiveRequest | receive, createInventoryItem, receipt reference validation | InventoryController, PurchasingService, Pawn custody; explicit audit ownership at HTTP boundary |
| InventoryMoveRequest | moveStock | InventoryController::moveStock |
| InventoryIssueRequest | issueStock | InventoryController (Manual context), SalesService (Sale), PurchasingService (PurchaseReturn) |
| InventoryReservationRequest | reserveForSale | SalesService |
| InventoryReservationReleaseRequest | releaseSaleReservation / releaseLockedStockReservation | Sales cancellation; typed full/partial release |
| InventoryAdjustmentRequest | adjustStock | InventoryController::adjustStock |
| InventorySaleReturnRequest | receiveSaleReturn | SaleReturnService |
| InventoryMovementContext | appendMovement | Inventory operation orchestration; never accepted from HTTP |
| InventoryStockCommand | runIdempotent | Shared typed base for stock operation requests |

1. Controllers validate HTTP arrays and call fromValidated(...) at the boundary.
2. Business callers construct DTOs directly using named constructor arguments.
3. Orchestration reads properties such as inventoryItemCode, locationCode, quantity, and serializedItemCodes.
4. Internal idempotency record IDs travel through InventoryMovementContext rather than hidden request-array keys.
5. runIdempotent serializes a DTO only when building the idempotency payload; the request key is excluded from its semantic payload.
6. Persistence attribute arrays remain inside explicit repository calls.
7. Arrays of serialized codes/identifiers remain typed collections, not generic operation payloads.
8. Public issue requests always get Manual context; public factories cannot select trusted source provenance.
9. The existing shared identifier normalizer is reused by receipt and adjustment DTOs.

### Version-dependent contract integration

- Location edits require update_key, compare it under the location lock, and advance it on update.
- Public stock moves require update_key and compare it under the item lock.
- Physical movements, reservation creation, and reservation release advance item versions.
- Reservation release locks the item before re-locking its reservation.
- Inventory item/location resources expose update_key; the frontend sends the selected item's version with moves.
- Mismatches use the existing AlreadyUpdatedException.
- Checkpoint publication remains derived maintenance and does not advance stock versions.
- Full ordered-lock, availability, audit, gating, and stale-conflict UI work remain in their existing checklist steps.

### Read contracts already used

| Contract | Current use |
|---|---|
| InventoryListFilter | InventoryRepository::itemPage |
| InventorySerializedItemFilter | InventoryRepository::serializedItemPage |
| InventoryPageFilter | InventoryRepository::movementPage; base pagination validation |

INV-21 now uses these filters in typed paginated read services. Controller activation remains in INV-26.

### Response contract activation

InventoryLocationDetail is activated for location creation/update. INV-21 constructs InventoryItemSummary, InventoryItemDetail, InventoryLocationBalance, InventorySerializedItemDetail, InventoryMovementDetail, and Inventory page DTOs in the new read services; their HTTP activation remains INV-26.

INV-22-25 and INV-ADJ-01 activate InventoryMutationResult for move, receipt, reservation creation, typed release, issue, and adjustment. Purchasing, Sales reservation consumers, Pawn custody, and frontend receipt/move/issue/adjustment typings are adapted narrowly. Existing item/history array readers and sale-return response consumers remain for coordinated INV-26-28 migration.

### Review and verification

- [x] Replace InventoryService generic operation input arrays with typed contracts.
- [x] Update controllers and all current Inventory callers together.
- [x] Add adjustment, sale-return, and internal movement-context contracts.
- [x] Integrate mandatory version fields with their public consumers.
- [x] PHP syntax checks on 13 affected backend files.
- [x] User review acceptance.
- [ ] Runtime and concurrency verification when authorized.

No tests, migrations, jobs, or database operations were run.

## Monthly inventory checkpoints

### Decision and implementation status

Monthly physical-stock checkpoints are now included in LonePawn 2.0 Part 1. This supersedes the earlier decision excluding all balance projections. Movement history remains the canonical source. There is no continuously updated balance table and no deletion or archival of movements.

The migration, model, repository queries, service, job, recovery command, and schedule registration are implemented and await review. No migrations, jobs, commands, tests, or database queries have been executed by this change.

### Quantity calculation

For each tenant, Inventory item, and physical location:

1. Find the latest completed checkpoint whose stored cutoff has passed.
2. Read its exact closing quantity as the opening quantity.
3. Add incoming movements not covered by that checkpoint.
4. Subtract outgoing movements not covered by that checkpoint.
5. Subtract live active reservations when calculating operational availability.
6. Continue evaluating location restrictions, Ownership, packs, pledges, and serialized identity through their existing live workflows.

If the previous month is unavailable, use the latest older checkpoint and a longer live tail. If no checkpoint exists, calculate from source movements. This fallback preserves correctness during first deployment, delayed jobs, or missed schedules.

A checkpoint covers a movement only when both conditions hold:

- Its ID is at or below the checkpoint's movement watermark.
- Its server recording time is before the checkpoint's UTC cutoff.

Therefore live deltas include IDs above the watermark **or** recording times on/after the cutoff. A missing recording timestamp is never silently removed from live reads. All normal application appends now assign recording time in the repository.

Example: October closes with quantity 10 and watermark 200. November receipt ID 195 was allocated before the November 2 summarizer ran; its recording time is after the November 1 cutoff, so its quantity 2 remains live. A later issue ID 201 with quantity 1 also remains live. Current quantity is 10 + 2 - 1 = 11.

### Month and time policy

1. Resolve each tenant's configured timezone using the existing business clock.
2. A month is identified by its first local calendar date.
3. Store that timezone and the following month's local midnight converted to UTC as the exclusive cutoff.
4. Include movements recorded before that cutoff.
5. Use server-assigned movement recording time, not business occurrence time, for checkpoint membership.
6. Backdated business dates do not remove newly recorded movements from current stock.
7. Existing checkpoints retain their stored boundary if the tenant changes timezone.
8. Reject current/future month requests and malformed YYYY-MM input.
9. Never interpret a monthly checkpoint as an Accounting close or a financial posting.

This is an operational quantity checkpoint, not a retrospective report by business occurrence date. Timestamp changes or direct database writes that bypass application append rules are unsupported.

### Storage

#### inventory_monthly_checkpoints

- Tenant and Inventory item foreign keys.
- Closed month, tenant timezone, UTC cutoff.
- Highest committed movement ID observed under the item's anchor lock.
- Completion timestamp.
- Unique tenant/item/month constraint.
- Item versions are not incremented merely for writing derived checkpoints.

#### inventory_monthly_checkpoint_balances

- Parent checkpoint and location foreign keys.
- Exact DECIMAL(24,3) closing quantity.
- Unique checkpoint/location constraint.
- Zero balances are retained.
- Child rows are queried through their tenant-scoped parent.

#### Movement indexes

- tenant_id / inventory_item_id / created_at / id.
- tenant_id / inventory_item_id / id.

Indexes support the live tail predicates; MySQL execution plans and measured improvement remain unverified.

### Main orchestration: InventoryMonthlySummaryService::summarizeTenant(...)

1. Require execution outside an existing business transaction, so an earlier repeatable-read snapshot cannot contaminate a checkpoint.
2. Resolve and validate the tenant-local completed month.
3. Create a temporary TenantContext binding for this tenant.
4. Capture the current item-ID upper bound and stream that register in chunks of 100, so continuous new-item creation cannot prolong a run indefinitely.
5. Start one transaction for the next item.
6. Acquire the item's exclusive stock anchor lock.
7. Recheck item existence.
8. Check the unique tenant/item/month checkpoint; skip an already completed item.
9. Read the latest earlier checkpoint plus uncovered movements recorded before the requested cutoff.
10. Group exact incoming/outgoing effects by location using SQL DECIMAL arithmetic.
11. Read the movement watermark while the item remains locked.
12. Insert the checkpoint header and location quantities, batching inserts in groups of 500.
13. Commit the complete item checkpoint atomically.
14. Continue with the next item; an interrupted run can resume without rebuilding completed items.
15. Restore the original TenantContext in a finally block.
16. Return the number of newly published item checkpoints.

### Append and concurrency rules

1. Every application movement append must run inside its caller's business transaction.
2. InventoryRepository::createMovement(...) acquires/reuses the tenant-scoped item anchor before inserting a movement.
3. Assign recording timestamps from the server UTC clock, overriding caller timestamps.
4. The monthly summarizer locks the same item anchor before reading its source and publishing its checkpoint.
5. A waiting stock operation appends after the checkpoint transaction commits.
6. A stock operation that commits before the summarizer obtains the anchor is visible to the summarizer's fresh transaction snapshot.
7. The watermark alone never excludes current-month rows inserted before the scheduled job.
8. The unique month constraint and item lock make duplicate job dispatches idempotent.
9. A failed item transaction publishes neither its header nor partial location balances.
10. Different items/tenants can progress independently.
11. Existing full lock ordering, optimistic mutation versions, and strict availability work remain in INV-09/15–18.
12. Future authoritative calculations must add checkpointBalancesForItems(...) to movementRowsForUpdate(...) deltas under the reviewed item-lock/read protocol. The delta method no longer represents the full history by itself.

The existing legacy float/tolerance decisions in orchestration remain assigned to INV-16/17. Adding checkpoints does not claim those unrelated outstanding defects are fixed.

### Scheduled orchestration: SummarizeMonthlyInventoryMovementsJob::handle(...)

1. Choose the scheduled queue connection through RedisAvailability, matching SummarizeMonthlyFinancialMovementsJob.
2. Use the scheduled queue.
3. Wrap execution with OperationLogger.
4. For a root job, reuse TenantSettingRepository::allTenantIds().
5. Resolve and pin the completed month into each tenant job's payload, then dispatch a separate instance so failures can retry independently without drifting into a later month.
6. A tenant job invokes the Inventory summary service.
7. A null root-job month means each tenant's previous completed month at dispatch.
8. Use tenant-specific WithoutOverlapping middleware with a 60-second release delay and 7200-second lock expiry.
9. Keep database item locks and uniqueness as the authoritative concurrency protections.
10. Register the root job monthly on day 2 at 00:45 UTC, alongside the financial summary schedule. All tenant-local previous months have ended by then.
11. Until publication, balance reads keep using an older checkpoint or source history; they do not return stale quantities.

The scheduler and a worker consuming the scheduled queue must be running in deployment. This change does not start them. Existing worker timeouts/retry configuration apply; completed item commits make interrupted attempts resumable. Large-tenant runtime must be measured before deployment.

### Recovery command

inventory:summarize-monthly --tenant=TENANT_ID --month=YYYY-MM

1. Validate an optional tenant ID against the existing tenant registry.
2. Validate the selected month through the shared summary service.
3. Use the same per-item transaction and idempotency rules as the job.
4. Skip completed item checkpoints.
5. Report newly published checkpoint count.
6. Omit tenant/month options to process every tenant's previous completed month.

The command recovers missing checkpoints; it does not force-rewrite already published checkpoints. A destructive invalidation/rebuild tool is not implemented. Source movements remain retained so repair can be designed without data loss if required.

### Dependency-first checklist

- [x] INV-MS-01: additive checkpoint schema, constraints, indexes, model.
- [x] INV-MS-02: latest checkpoint, closing quantities, and uncovered-delta repository helpers.
- [x] INV-MS-03: append-side item anchor and server recording timestamps.
- [x] INV-MS-04: tenant-local per-item summary service and context restoration.
- [x] INV-MS-05: scheduled job using the financial summarizer's logging/queue/overlap pattern.
- [x] INV-MS-06: monthly registration and explicit recovery command.
- [x] INV-MS-07: use checkpoints in existing balance()/balances(), batch balances, and positive-location stock filters.
- [ ] User review acceptance for INV-MS-01–07.
- [ ] Execute verification only when authorized.

### Verification scenarios — not executed

1. First checkpoint matches a full source-ledger calculation.
2. Next month's checkpoint reuses the previous base and produces identical totals.
3. Receive, move, issue, same-item multiple locations, exact exhaustion, and zero-history items.
4. Current-month rows with IDs below the watermark remain in live quantities.
5. Later IDs and backdated business dates remain in live quantities exactly once.
6. Concurrent append vs. checkpoint; item locks prevent missing or duplicate quantities.
7. Concurrent jobs and reruns create one checkpoint per tenant/item/month.
8. Partial failure rolls back one item's complete checkpoint; earlier committed items can be skipped on retry.
9. Tenant isolation and original context restoration after success/failure.
10. UTC boundaries, tenant timezones, timezone changes, and completed-month rejection.
11. Missing prior month, delayed queue, first deployment, and missed schedules.
12. Reservations and serialized-item custody remain live rather than frozen.
13. Existing balance APIs and location filters agree with source history.
14. Future write-side consumers combine the checkpoint base and locked deltas correctly.
15. MySQL EXPLAIN and representative movement history verify tail/index performance.
16. Scheduler discovery, root fan-out, worker queue consumption, retries, and operational logging.

## Part 1 completion

- [ ] All INV-01 through INV-29 applicable deliverables are implemented and reviewed.
- [ ] INV-30 overall reanalysis and reviewed refactoring are complete after all implementation and wiring.
- [ ] All required acceptance verification is completed; unverified claims are not marked passed.
- [ ] Required caller integrations and public contract replacement are connected consistently.
- [ ] Remaining findings are assigned to their later module parts.
- [ ] User accepts Part 1 before advancing to Part 2 — Ownership.
