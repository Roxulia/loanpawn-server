# OWN-19 ordered locks and current reads

All OWN-19a–e implementation work is prepared for review. Runtime verification has not been run for this batch.

## Actual lock order

1. Caller-owned idempotency and business-source locks (purchase order/line or sale order/line), before either stock module. Multi-source callers acquire complete source sets in database ID order.
2. Complete existing Inventory item sets, ascending ID, through `InventoryService::prelockItems`. A newly created identity belongs to the creating transaction. Its row is the serialization anchor when no Ownership aggregate exists yet.
3. Existing Ownership aggregates ordered by Inventory identity ID, then aggregate ID.
4. Complete selected lot sets ordered by aggregate ID, then lot ID. Source commitments, receipt links, current memberships and piece commitments use their existing ascending primary-key locking queries after the aggregate is held.
5. Inventory custody through its existing `lockStockForOperation`: item anchors already held, then locations, reservations, serialized pieces, and current movement/balance reads. Do not enter a new item's anchor set from inside this phase.

`OwnershipService::prelockItems` delegates complete-set work to the internal `LockService`, which calls Inventory through its service and Ownership through its own repository. It does not create missing aggregates or modify quantities. Sales confirmation/cancellation/immediate fulfillment/delivery, Sale Return, and Purchasing receipt/return now use this helper at their existing prelock points. This changes locking only; payment and business workflow activation remain OWN-30 and Parts 4/5.

Single-item FIFO, receipt linking and quantity primitives already lock Inventory before their aggregate and lots. Payment-qualified acquisition now resolves its aggregate before locking the canonical source lot. Compatibility acquisition and quantity mutations follow the same order. Outer callers must supply complete item sets before their first per-line operation; the single-item methods cannot discover later lines in an enclosing transaction.

## Current reads

`lockedBalanceParts` acquires the tenant-bound aggregate and then performs locking reads for the completed checkpoint, its exact lot children and the uncovered movement tail. Child closings additionally join a lot belonging to the selected tenant/item. Both watermark and server recording cutoff remain in tail coverage, including null recording times. Receipt links, memberships and commitments continue to use their existing ordered `FOR UPDATE` paths in mutation services.

Compatibility UNIQUE checks and quantity validation now reuse `BalanceService::locked`, avoiding the earlier nonlocking SUM from a potentially older repeatable-read snapshot. Compatibility methods retain their existing float interfaces and validation semantics; exact API/caller replacement remains its planned later unit.

## Append and monthly publication anchor

`OwnershipRepository::lockAggregate(tenant, ownedItemId)` is the shared anchor. It requires an active transaction and returns only the matching tenant row. `createMovement` must acquire that anchor before inserting; missing aggregates reject, and payload data cannot override the authoritative tenant. All four application Ownership movement append sites go through this repository method. Callers acquire anchors before validation/downstream custody; repository enforcement is a final safeguard, not a substitute for that earlier order.

The OWN-27 monthly publisher must call `lockAggregate` at the start of each item transaction and retain it through current checkpoint/tail selection and header/children publication. It must not acquire Inventory or business-source locks after taking this anchor. This prevents a cycle with ordinary writers waiting on the aggregate. Monthly publication does not yet exist: this batch supplies its shared anchor and enforces the append side, without claiming the later publisher or its concurrency tests have passed.

## Review scenarios and evidence

`OwnershipLockQueryTest` adds focused real-database scenarios:

- Reverse/duplicate item input locks Inventory, then aggregates, then lots; quantities and business rows stay unchanged.
- An identity without an aggregate can be prelocked without manufacturing ownership.
- The summary/append aggregate lookup excludes foreign tenants.
- A malformed checkpoint child referencing another item's lot cannot supply its opening balance.
- Movement append takes its aggregate lock before insertion and cannot override tenant scope.

The existing independent-worker suite still covers competing acquisition/linking, sale versus pledge, reversed multi-item inputs and stale snapshots. Its complete-set prelock now calls the production Ownership helper. FIFO workers continue to obtain their locks from the real single-item allocator. Existing append-versus-summary and duplicate-publication cases remain dependent on OWN-27.

No PHPUnit, database queries, migrations, workers, scheduler or development server were run for this batch. PHP syntax and whitespace results are recorded in the main checklist. Query-log assertions describe ordering, not measured contention or latency; observed lock-wait evidence must come from separately authorized independent-connection runs.

## Authorized execution follow-up — 2026-10-24

The user subsequently requested "run related tests". The existing guarded `lonepawn_test` database passed the read-only OWN-15 structure check on MySQL 8.0.40. No migrations or services were started.

- New `OwnershipLockQueryTest`: **5 tests / 19 assertions passed**.
- Related Ownership batch (LockQuery, Acquisition, Unique, Source, SourceQuery, BalanceQuery, Sale, ReceiptLimit, and four FIFO classes): **101 tests / 1,412 assertions; 99 passed, 2 errored**. Both errors are in `OwnershipUniqueTest`, at `reserveUnique`: the fixture first reserves physical Inventory and then requests an Ownership reservation, whose physical-availability check rejects. They occur before the intended fully-reserved/reacquisition assertion. No expectation was changed; these scenarios remain unresolved rather than verified.
- Selected `OwnershipConcurrencyTest` acquisition/link/final-unit/reversed-input cases: **5 tests / 167 assertions passed**, including both tracking-mode provider cases. The waiting-sale/new-membership case passed in the following regression batch. These six cases use the existing independent-worker/observed-lock-wait assertions. The four FIFO contention cases also passed within the 101-test batch.
- Waiting-sale case plus Inventory/Pawn regression classes: **20 tests / 142 assertions; 19 passed, 1 errored** because the Pawn photo fixture could not create its temporary storage directory. An authorized rerun with filesystem access reached the actual photo assertion: signed URLs differed by one second in expiry/signature. One diagnostic retry reproduced that assertion failure (**1 test / 6 assertions** each). The photo check remains unresolved; no locking regression is inferred from the URL comparison.

The first focused run could not write PHPUnit's result cache despite passing its tests. Later runs used `--do-not-cache-result`. Monthly publisher/append races remain pending OWN-27 and were not selected. The full Ownership suite and complete Purchasing/Sales workflows were not claimed or executed by this selection. Review acceptance and complete verification remain pending.
