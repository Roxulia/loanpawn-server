# Dedicated FIFO test review

## Accepted contract

The dedicated test review was accepted and the user authorized **OWN-17e FIFO** and **OWN-17f pagination** implementation with test execution. The user subsequently requested "marks done and plan for next phase": both backend implementations are accepted. Public API/UI activation and complete business workflow verification remain separate; next phase is OWN-18 source/link repository helpers.

The cart does not reserve. At checkout the caller owns a transaction, resolves/locks its business source, asks FIFO to allocate, and applies the returned rows before commit. Allocation alone writes no business, reservation, replay, audit, or checkpoint records. A database lock lasts through this short transaction, never through cart editing.

Proposed entrypoint: `OwnershipService::allocateFifo(string $inventoryItemCode, quantity, array $serialNumbers = []): OwnershipFifoResult`. The internal result belongs under `app/DTOs/Ownership/Internal/ResponseObjects`; no public endpoint is introduced. Exact input parsing reuses `InventoryQuantity`. The result's `toArray()` contract is:

```text
inventory_item_code, owned_item_code, quantity (canonical three-decimal string), allocations[]:
    lot_code, location_code, quantity, serialized_item_codes[], unit_cost_basis, currency_code
```

QUANTITY selects lots by acquired date then ID and locations by ID. SERIALIZED requires caller-supplied **serial identifiers**, not internal IDs or public piece codes. Distinct serial count equals the whole quantity. Resolve tenant/item-bound identifiers to current pieces and their actual lot/location; do not substitute older pieces. Group output by lot/location, ordered by lot date/ID, location ID and piece ID. UNIQUE accepts exactly one and no serial list. Eligible stock is received, linked, held at an active sellable shop, unreserved and unpledged. The result preserves each lot's independent cost/currency; no conversion or monetary aggregation occurs.

Inventory resolves the item through `InventoryService::findForOwnershipByCode`; missing/foreign codes propagate `TenantNotFound` and `inventory.item_not_found`. Ownership resolves its own aggregate and raises `TenantNotFound` with `ownership.not_found` when absent. FIFO must reuse that Ownership resolver, rather than recreate a second lookup/error policy. This resolver is an implementation dependency, not an additional mocked authority. The Inventory-delegation test checks the exact thrown exception instance; real missing/foreign/missing-Ownership scenarios also exercise persistence boundaries.

Invalid business selections use `InvalidTenantRequest` / `INVALID_TENANT_REQUEST`. Insufficient eligible quantity uses `ownership.insufficient_lot_balance`; invalid serial selection uses `ownership.piece_selection_invalid`; existing Inventory quantity/tracking messages remain authoritative. No partial allocation succeeds. A missing caller transaction is a programming error: `LogicException('Ownership FIFO allocation requires an active transaction.')`, consistent with Inventory's internal transaction guards.

## What the tests establish when executed

| Test class | Expectations |
|---|---|
| `OwnershipFifoTest` | One/multiple lots, date-before-ID ordering, date ties, exact thousandths, partial last lot, location order, unreceived/unlinked stock, ownership and physical reservations, pledges, restricted locations, explicit selected serials including newer lots, stable exact-piece order, UNIQUE, separate cost/currency snapshots and tenant-scoped serial resolution. Allocation leaves all captured records unchanged. |
| `OwnershipFifoValidationTest` | Inventory error delegation; real missing/foreign items; absent Ownership; zero/negative/malformed/over-precision/overflow input; insufficient stock; missing/duplicate/mismatched/unknown/foreign serials; public-code-versus-serial distinction; unlinked/reserved/pledged/issued pieces; mixed valid/invalid selection; fractional serialized quantity and serial input on QUANTITY. Rejections compare domain class/code/message and unchanged records. |
| `OwnershipFifoCallerTest` | Real caller applies exact lot reductions and physical issues; depleted lots stay excluded; selected newer serial is issued while older piece remains; injected failure after the first actual issue rolls back all business effects; missing transaction rejects; literal checkpoint-plus-tail allocation agrees with independent full-ledger totals. |
| `OwnershipFifoConcurrencyTest` | Independent guarded MySQL processes observe an actual lock wait caused by the allocator. Distinct caller sources avoid accidental contention solely on source rows. First checkout takes four of five and second rejects; same serial is issued only once; rollback restores quantity/piece availability for the waiting caller. |

`OwnershipFifoTestCase` reuses existing purchase/ledger baselines, actual Inventory receipt, actual Ownership linking, and full row snapshots. Seeded acquisition facts isolate FIFO and do **not** prove purchase acquisition correctness. `received` and `linked` distinguish physical custody from its acquisition-lot association; neither is a FIFO input. Tenant/serial uniqueness already prevents ambiguous identifiers inside a tenant; the same identifier in a different tenant is explicitly covered without weakening that constraint.

The minimal `OwnershipFifoCaller` is test-only sale provenance plus real module primitives, shared by caller and worker cases. It contains no allocation algorithm, replacement stock calculator, cash posting or complete checkout simulation. Sources are locked before allocation, but stock is never prelocked by the harness. Caller retries/idempotency remain the existing business operation's responsibility. Loan pledge retains Ownership while changing commitment/custody; forfeiture consumes its existing pledge rather than allocating unrelated FIFO lots. Full loan and sale financial orchestration remain Parts 8 and 5 respectively.

## Dependencies and limits

- `allocateFifo` and `OwnershipFifoResult` are implemented. The accepted scenarios run against real receipt-link and source-bound quantity helpers, not replacement fixture calculations. New allocation does not mutate stock; its caller applies the selected rows before committing.
- Required current-read/locking and receipt/commitment foundations are included; this does not declare OWN-18–25 complete. Legacy Sales calls use explicit compatibility methods pending coordinated OWN-30 activation. Existing `fifoLots()` consumers and public endpoints remain compatible.
- Generic Packing has no schema/service/fixture yet. Packed-stock exclusion remains a required Part 7 handoff: add its dedicated real-fixture case before activating packs with FIFO. No fictional pack tables or successful packed-stock coverage are claimed here.
- Checkpoint facts test reading, not publication. Query plans/timing remain OWN-33. Committed fixtures/workers require the explicitly prepared disposable MySQL schema and lock-inspection permissions.
- The implementation task runs authorized PHPUnit tests and their guarded MySQL workers. User explicitly approved only the seven existing OWN-15 migrations against `lonepawn_test`; structure verification passed. No fresh rebuild, seeding, normal application migration, scheduler, queue or development server was run.

## Initial test-writing verification — 2026-10-22

PHP syntax passed for all eleven distinct added/modified PHP files. The four feature test classes and FIFO task/worker loaded through Composer without booting Laravel or invoking tests. Tracked diff and new-file whitespace checks passed. Runtime results and user review of the new cases remain pending. No production application files changed.

The preceding paragraph is historical evidence from test writing. Current runtime/implementation evidence supersedes its pending status.

## Implementation review notes

- FIFO uses item → Ownership aggregate → lots/link/membership state → Inventory custody locks. Balance reads use current locking checkpoint/tail rows, even if a caller already established an older repeatable-read snapshot.
- Quantity allocation respects original receipt-backed lot capacity and shared physical location availability. Serial allocation resolves explicit identifiers and retains actual current lot/location memberships. No cart reservation is introduced.
- Pagination uses SQL filters/counts, stable ordering and bounded DTOs for item, lot, movement, receipt and piece pages. `OwnershipPaginationQueryTest` adds empty/out-of-range, tracking/tenant and selected-receipt piece-page boundaries. Existing warmed query-count and serialization assertions remain unchanged.
- A Windows stdin limitation caused the initial concurrency failures: nonblocking input is unavailable. The shared worker now has a parent-timeout-bounded fallback. All lock-wait assertions remain required. Four source models explicitly name their existing `sales_*` tables.
- A fixture-only foreign-version lookup was corrected to allow known foreign fixture codes to reach service validation. It remains restricted to recorded fixture tenant IDs; no production tenant isolation was weakened.
- The expanded later receipt-orchestration case requiring `linkPurchaseReceipt()` is deferred to OWN-23 at the user's direction. No test is weakened or marked passing for that missing operation. Full later-phase suites remain pending.

## Final execution evidence — 2026-10-22

| Final selected run | Result |
|---|---|
| FIFO allocation/validation/caller/concurrency; Ownership bounded reads, balance and pagination queries; quantity/cost/command/contract/page/operation DTO tests | **364 tests / 4,256 assertions passed** on prepared disposable MySQL where applicable |
| Existing Inventory MySQL verification and Pawn Inventory integration | **19 tests / 116 assertions passed** |
| Ownership schema metadata | Passed eight-table OWN-15 structure check on `lonepawn_test`, MySQL 8.0.40 |
| Changed PHP and whitespace | PHP syntax and tracked/new-file whitespace checks passed |

These are **383 selected passing tests / 4,372 assertions across the final two runs**, not a claim that the entire Ownership suite passes. The expanded support run stopped at the later absent `linkPurchaseReceipt()` orchestration method. A combined regression run also observed the existing photo test compare URLs generated on opposite sides of a one-second boundary; the unchanged Inventory/Pawn suite passed on its separate final rerun. No photo-signing or later-phase orchestration change was added to fix those out-of-scope cases.

OWN-17e/f implementation is accepted. Public routes/UI, generic Packing, monthly publication/performance and full business workflow acceptance remain their owning phases. This acceptance update reruns no tests or migrations.
