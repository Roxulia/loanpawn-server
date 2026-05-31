# Background Event-Based Online Sync Plan

## Summary

Move online sync to a non-blocking background pipeline for both LonePawn Desktop and LonePawn Server.

Desktop writes local business changes to an outbox and submits sync batches in the background. The server accepts each batch quickly, queues processing, stores durable per-event results, sends a realtime notification when possible, and lets desktop pull results later if it was offline.

Use event-based sync for core pawnshop workflows and table-based sync only for lower-risk configuration data. Avoid timestamp-based conflict decisions. Use server-controlled sequencing and `update_key` version checks instead.

## Goals

- Keep desktop fully usable while sync is pending, processing, failed, or offline.
- Prevent direct table sync from bypassing domain services for financial workflows.
- Preserve existing desktop data through a safe migration path.
- Keep sync results on the server until the desktop pulls and acknowledges them.
- Support realtime completion notification without depending on realtime delivery for correctness.

## Key Changes

- Replace immediate table-log sync with a desktop `sync_events` outbox for financial workflows.
- Keep the existing desktop `sync_log` only for migration and backward compatibility.
- Stop sending direct desktop accounting logs for event-based workflows.
- Generate server accounting only from accepted business events.
- Keep table-based sync only for settings, users, branding/contact defaults, material types, interest types, and expense types.
- Replace timestamp-wins conflict logic with `eventId`, `server_sequence`, and `expectedVersion` / `update_key`.
- Process server sync batches through Laravel queue jobs instead of the request lifecycle.
- Use Laravel Reverb to notify online desktop clients when a batch completes.
- Store durable batch and event results so offline desktop clients can pull them later.

## Desktop Migration

Desktop apps may already contain local data and pending `sync_log` rows, so the desktop update must include a migration before enabling the new sync flow.

Add a local migration from `sync_log` to `sync_events`.

Existing unsynced `sync_log` rows should be converted conservatively:

- Loan contract, interest payment, redemption, debt, and expense rows should become business events where the source data is complete enough.
- Direct `Accounting` rows should be marked migrated or ignored for server push because the server regenerates accounting from accepted business events.
- Rows that cannot be safely converted should remain in a legacy review state and be shown in sync diagnostics.

The desktop should add or maintain local fields for:

- `client_device_id`
- `local_event_id`
- `server_batch_id`
- `server_sequence`
- `server_aggregate_id`
- `expected_update_key`
- `sync_status`
- `sync_error`

Desktop business services must write local domain changes and event outbox rows in the same SQLite transaction.

## Desktop Sync Flow

Desktop sync must run in the background and must not block user workflows.

The desktop background sync loop should:

1. Collect pending `sync_events` in small batches.
2. Submit the batch to the server.
3. Store the returned `syncBatchId`.
4. Continue user workflow immediately.
5. Listen for Laravel Reverb notification when online.
6. Poll or pull batch results on startup, after manual sync, after notification, and on a timed interval.
7. Apply accepted server results by `server_sequence`.
8. Update local server IDs, aggregate versions, and canonical state.
9. Keep rejected events visible for user or admin reconciliation.
10. Acknowledge pulled results after local application succeeds.

Desktop must treat realtime notifications as hints only. Pulling stored server results is the source of truth.

## Server API

Add new authenticated tenant sync endpoints:

- `POST /api/tenant/sync/batches`
  - Stores submitted events.
  - Dispatches a queue job.
  - Returns `202 Accepted` with `syncBatchId`.
  - Does not apply domain mutations in the request lifecycle.

- `GET /api/tenant/sync/batches/{syncBatchId}`
  - Returns batch status and per-event results.

- `GET /api/tenant/sync/results?unpulled=1`
  - Returns durable sync completion/result notifications that the desktop has not pulled.

- `GET /api/tenant/sync/events?after_sequence={n}`
  - Returns accepted canonical server events after the desktop's last known sequence.

- `POST /api/tenant/sync/results/{id}/ack`
  - Marks a pulled result as acknowledged after desktop applies it locally.

Keep the current `POST /api/tenant/online-sync` only as a legacy/table-sync endpoint until migration is complete.

## Server Tables

Add server tables for durable background sync state.

### `tenant_sync_batches`

Stores one submitted desktop sync batch.

Required fields:

- `id`
- `tenant_id`
- `client_device_id`
- `status`
- `submitted_count`
- `accepted_count`
- `rejected_count`
- `failed_count`
- `started_at`
- `finished_at`
- `expires_at`
- timestamps

### `tenant_sync_events`

Stores submitted event payloads and processing results.

Required fields:

- `id`
- `tenant_id`
- `sync_batch_id`
- `event_id`
- `event_type`
- `aggregate_type`
- `aggregate_id`
- `expected_version`
- `payload_json`
- `client_occurred_at`
- `status`
- `rejection_code`
- `rejection_message`
- `latest_server_state_json`
- `resulting_version`
- `server_sequence`
- timestamps

Enforce tenant-scoped uniqueness for `event_id`.

### `tenant_sync_notifications`

Stores durable completion notices for desktop clients.

Required fields:

- `id`
- `tenant_id`
- `client_device_id`
- `sync_batch_id`
- `type`
- `payload_json`
- `is_pulled`
- `pulled_at`
- `acknowledged_at`
- `expires_at`
- timestamps

### `tenant_client_devices`

Optional but recommended.

Tracks desktop installations and pull cursors.

Useful fields:

- `id`
- `tenant_id`
- `client_device_id`
- `device_name`
- `last_pulled_sequence`
- `last_seen_at`
- timestamps

## Server Processing Flow

The server queue job processes each batch tenant-scoped and idempotently.

For each event:

1. Check `event_id` idempotency.
2. If already processed, return the original stored result.
3. Resolve the current aggregate and version.
4. Compare current version with `expectedVersion` / `update_key` where required.
5. Reject stale events without mutating business tables.
6. For valid events, call existing domain services.
7. Let domain services generate accounting and audit logs.
8. Increment or capture the resulting aggregate version.
9. Assign `server_sequence`.
10. Store accepted or rejected result.

When a batch finishes:

- Update `tenant_sync_batches` status and counts.
- Write a durable `tenant_sync_notifications` row.
- Send a Reverb notification to the tenant/device channel if the desktop is online.

Production deployment must run queue workers, for example with `php artisan queue:work`, under a process manager. The current Laravel database queue driver is acceptable for the first implementation.

## Event Contract

Desktop sends business events with:

- `eventId`
- `eventType`
- `aggregateType`
- `aggregateId`
- `expectedVersion`
- `clientOccurredAt`
- `payload`

The server validates each event against current tenant state.

When the current version matches `expectedVersion`, the server:

- accepts the event
- runs the normal domain service
- generates accounting if needed
- increments or records the aggregate version
- assigns `server_sequence`
- writes the sync event and audit log

When the current version does not match `expectedVersion`, the server:

- rejects the event as a conflict
- stores the rejection result
- returns the latest server state or latest events for desktop reconciliation

## Conflict Strategy

- Do not use timestamps to decide winning changes.
- Use server version comparison.
- `currentVersion === expectedVersion` means the event is safe to apply.
- Version mismatch means the client event is stale.
- Use `server_sequence` as the canonical timeline order.
- Store `clientOccurredAt` only for audit and display.
- Default equal or ambiguous conflicts to server state.
- Desktop must not silently delete rejected events.

## Module Policies

### Loan Contract Creation

- Use `eventId` for idempotency.
- Server creates the canonical slip, customer, collateral rows, interest schedule, and accounting.
- Desktop maps local slip and collateral IDs to server IDs after acceptance.

### Interest Payment

- Require loan contract expected version.
- Include selected interest rows and their `update_key` values.
- Server recalculates and validates payable interest.
- Reject the event if the slip was already redeemed, expired, or interest state changed.

### Redemption

- Require expected versions for slip, unpaid interest rows, and debt rows.
- Server validates the current payable amount before applying.
- Server updates slip, collateral, debts, interest payments, redemption record, and accounting through domain services.

### Debt

- Debt creation can be append-like and idempotent by `eventId`.
- Debt update, delete, and mark-paid events require expected version.
- Accounting impact is generated by server logic where applicable.

### Expense

- Expense creation can be append-like and idempotent by `eventId`.
- Expense update and delete require expected version.
- Accounting is created, updated, or removed by server logic.

### Accounting

- Do not accept direct desktop accounting sync for event-based workflows.
- Accounting records must be derived from accepted business events.
- Desktop may keep local accounting for offline reports, but server accounting remains canonical after sync.

### Settings, Users, And Master Data

- Use table sync with `update_key` compare-and-swap.
- Reject stale updates instead of applying timestamp overwrite.

## Result Retention

Keep completed sync result data until the desktop acknowledges it.

If a completed result is not acknowledged, keep it for 30 days, then expire it through scheduled cleanup.

Acknowledged results may be cleaned up after a shorter operational retention period if no audit requirement depends on them. The canonical accepted event log should remain available for timeline/audit needs.

## Test Plan

- Desktop migration converts pending financial `sync_log` rows into `sync_events`.
- Desktop migration safely ignores or marks direct accounting rows so they are not pushed as server accounting.
- Desktop can continue creating local transactions while previous sync batches are pending.
- Submitting a batch returns `202 Accepted` quickly.
- Batch submission creates a queued job without applying domain mutations in the request lifecycle.
- Queue job accepts a valid event when expected version matches current server version.
- Queue job rejects stale events when server version has advanced.
- Duplicate `eventId` does not reapply mutations or accounting.
- Accepted loan, expense, debt, interest payment, and redemption events generate accounting through domain services.
- Accounting payload from desktop is ignored for event-based workflows.
- Server returns accepted events ordered by `server_sequence`.
- Reverb notification is sent after batch completion when desktop is online.
- Offline desktop can later pull unpulled results and acknowledge them.
- Unacknowledged results remain available until `expires_at`.
- Tenant and device isolation are enforced across batches, events, notifications, audit logs, accounting, and table sync.
- Table sync rejects stale `update_key` updates.

## Assumptions

- JavaFX `lonepawn_desktop` is the offline client in scope.
- Flutter `lonepawn_app` is out of scope unless it becomes the active offline client.
- Existing `update_key` is the v1 aggregate version.
- Server IDs are canonical.
- Desktop must maintain local-to-server ID mapping after accepted create events.
- Business services remain the only place that mutates financial workflow tables.
- Laravel Reverb is required for realtime notification, but pull-based durable results remain the source of truth.
- Result retention default is until acknowledged, with 30-day expiry for unacknowledged completed results.
- Production will run Laravel queue workers and Reverb alongside the web server.
