# Inventory monthly checkpoints — scope amendment

## Decision and implementation status

Monthly physical-stock checkpoints are now included in LonePawn 2.0 Part 1. This supersedes the earlier decision excluding all balance projections. Movement history remains the canonical source. There is no continuously updated balance table and no deletion or archival of movements.

The migration, model, repository queries, service, job, recovery command, and schedule registration are implemented and await review. No migrations, jobs, commands, tests, or database queries have been executed by this change.

## Quantity calculation

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

## Month and time policy

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

## Storage

### inventory_monthly_checkpoints

- Tenant and Inventory item foreign keys.
- Closed month, tenant timezone, UTC cutoff.
- Highest committed movement ID observed under the item's anchor lock.
- Completion timestamp.
- Unique tenant/item/month constraint.
- Item versions are not incremented merely for writing derived checkpoints.

### inventory_monthly_checkpoint_balances

- Parent checkpoint and location foreign keys.
- Exact DECIMAL(24,3) closing quantity.
- Unique checkpoint/location constraint.
- Zero balances are retained.
- Child rows are queried through their tenant-scoped parent.

### Movement indexes

- tenant_id / inventory_item_id / created_at / id.
- tenant_id / inventory_item_id / id.

Indexes support the live tail predicates; MySQL execution plans and measured improvement remain unverified.

## Main orchestration: InventoryMonthlySummaryService::summarizeTenant(...)

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

## Append and concurrency rules

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

## Scheduled orchestration: SummarizeMonthlyInventoryMovementsJob::handle(...)

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

## Recovery command

inventory:summarize-monthly --tenant=TENANT_ID --month=YYYY-MM

1. Validate an optional tenant ID against the existing tenant registry.
2. Validate the selected month through the shared summary service.
3. Use the same per-item transaction and idempotency rules as the job.
4. Skip completed item checkpoints.
5. Report newly published checkpoint count.
6. Omit tenant/month options to process every tenant's previous completed month.

The command recovers missing checkpoints; it does not force-rewrite already published checkpoints. A destructive invalidation/rebuild tool is not implemented. Source movements remain retained so repair can be designed without data loss if required.

## Dependency-first checklist

- [x] INV-MS-01: additive checkpoint schema, constraints, indexes, model.
- [x] INV-MS-02: latest checkpoint, closing quantities, and uncovered-delta repository helpers.
- [x] INV-MS-03: append-side item anchor and server recording timestamps.
- [x] INV-MS-04: tenant-local per-item summary service and context restoration.
- [x] INV-MS-05: scheduled job using the financial summarizer's logging/queue/overlap pattern.
- [x] INV-MS-06: monthly registration and explicit recovery command.
- [x] INV-MS-07: use checkpoints in existing balance()/balances(), batch balances, and positive-location stock filters.
- [ ] User review acceptance for INV-MS-01–07.
- [ ] Execute verification only when authorized.

## Verification scenarios — not executed

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
