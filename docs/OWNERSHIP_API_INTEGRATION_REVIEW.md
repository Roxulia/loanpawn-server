# Ownership API integration (OWN-29)

OWN-22 through OWN-28 were accepted by the user before API activation. OWN-29 is implemented and awaiting review after testing. OWN-30 caller integration and OWN-31 frontend integration remain separate.

All paths below are relative to `/api/tenant/ownership`. Existing tenant authentication, resolution, license, Ownership and Inventory feature middleware remain active.

| Method and path | Permission | Response/inputs |
| --- | --- | --- |
| GET `items` | `list_owned_item` | Paginated summaries; search, tracking_mode, holding |
| GET `items/{ownedItemCode}` | `list_owned_item` | Current detail and locations; no embedded lot/history collections |
| GET `items/{ownedItemCode}/lots` | `list_owned_item` | Paginated lots; currency_code, holding |
| GET `items/{ownedItemCode}/movements` | `list_owned_item` | Paginated append-only history |
| GET `items/{ownedItemCode}/lots/{lotCode}/receipt-link-candidates` | `list_owned_item` | Paginated receipt candidates, or pieces when purchase_receipt_line_code is selected; optional search |
| POST `items/{ownedItemCode}/lots/{lotCode}/receipt-links` | `manage_owned_item` | purchase_receipt_line_code, quantity, update_key, optional serialized_item_codes; required Idempotency-Key header |

Read pages accept page >= 1 and per_page 1..100, default 25. New quantities remain decimal strings in responses. List and movement endpoints now return page objects rather than legacy arrays; the frontend must adopt these contracts in OWN-31. Manual acquisition stays behind its existing inactive feature gate. No public paid-source acquisition, sale or pledge bypass was added. Existing Pawn endpoints and acquisition consumers keep their current contracts for OWN-30.

The controller validates and constructs existing DTOs, calls the service and serializes its response. Tenant and source authority remain service-resolved. Foreign item paths return 404. A payload idempotency_key cannot replace the header. Identical receipt-link replay returns the saved typed result and Idempotent-Replay header before rechecking the old version; changed payload or stale new requests conflict without effects. MySQL JSON field ordering is normalized through OwnershipResult before rendering replay, without updating saved records.

HTTP coverage was extended for paginated list/detail/lots/history, invalid filters and writes, serialized receipt candidate selection and linking, body-key rejection, and foreign read isolation. Existing real middleware, version, replay and manual gate cases remain. Detail now retains PENDING while quantity awaits receipt, rather than treating zero received-but-unlinked quantity as LINKED.

## Verification

The user authorized migrations in the testing environment and explicitly instructed that old features must not be fixed.

- Full suite on the guarded disposable MySQL testing database: **1,003 tests, 12,955 assertions; 921 passed, 54 errors, 28 failures**, in 4m46s. All **247 Ownership feature tests** passed, including all **16 HTTP/replay cases**. No Ownership test appears among the failures/errors.
- Unchanged old-feature issues include missing accountId constructor arguments, duplicate package seeds, username/license_key length limits, absent financial account/default-currency setup, permission expectations, and the known financial response reference_number assertion. These were reported without modifying old-feature code or tests.
- The initial default SQLite full run completed 1,003 tests with 263 errors: 262 repeat failures in an existing supplier-payable UPDATE JOIN migration, plus the known financial unit error. The subsequent MySQL run exercised those feature tests beyond migration setup without changing that migration.
- Both runs used APP_ENV=testing. The MySQL rerun loaded settings through the existing TestDatabase disposable-database guard, removed any DB_URL override, and allowed test-owned schema refreshes only on that validated test destination. The temporary launcher was removed afterward. No development server or scheduler was started.

Logs: storage/logs/overall-api-mysql-test-check.txt (primary full run), storage/logs/overall-api-test-check.txt (SQLite compatibility result).
