# Version 2.0 tests

The helper and parent-class rules in `../AGENTS.md` apply to 2.0 tests and their support code. Earlier-version tests keep their existing structure.

## Shared helpers

- `MySqlTestCase` selects a disposable MySQL database, isolates runtime stores and rolls back fixture transactions. `InventoryMySqlTestCase` and `OwnershipMySqlTestCase` share this parent.
- `PawnInventoryTestCase` adds the Pawn fixture and restores authentication and clocks.
- `CommittedInventoryTestCase` and `CommittedOwnershipTestCase` create committed fixtures when independent connections must see the same rows. They stop workers before removing only their own records, then always call parent teardown.
- `TestDatabase` shares environment selection, connection configuration and rollback. It keeps the established `inventory_testing` connection name.
- `TestWorker` handles processes, buffered JSON messages, bounded waits and shutdown. `InventoryWorker` and `OwnershipWorker` supply their module protocols. Tests obtain the active fixture's helper through `worker()`.
- `WorkerTask` runs child-process setup, reporting and failure cleanup. `InventoryTask` and `OwnershipTask` retain their real module actions and lock order.
- `DatabaseCheck` opens a read-only metadata connection without booting Laravel. Inventory checks the baseline; Ownership checks its additional prerequisites and preserves its pending-schema exit status.

## Existing entrypoints

The `inventory_database_preflight.php`, `ownership_database_preflight.php`, `inventory_concurrency_worker.php`, and `ownership_concurrency_worker.php` paths remain available under `Support`. Their payloads, release messages and exit codes retain the existing contract.

Do not execute tests or workers unless requested. Never run migrations or start a development server for this refactor. PHP syntax checks and static review do not establish runtime correctness.

## Ownership tests through OWN-12

`OwnershipQuantityTestCase` shares real receipt, sale and pledge setup with literal ledger/projection assertions. `OwnershipHttpTestCase` adds real authenticated middleware requests. `OwnershipMonthlyTestCase` reuses `CommittedOwnershipFixture` for independent-process visibility and exact-tenant cleanup. Worker payloads remain allowlisted and contain only fixture references.

See [the Ownership test review](../docs/OWNERSHIP_TEST_REVIEW.md) for child-by-child coverage, missing production/schema prerequisites and the distinction between written expectations and pending execution. Tests do not substitute fake balances or fake monthly calculations for missing services.
