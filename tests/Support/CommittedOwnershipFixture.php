<?php

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Isolated committed fixtures for independent-connection and monthly-publication tests. */
abstract class CommittedOwnershipFixture extends OwnershipMySqlTestCase
{
    use OwnershipWorkerProcess;

    protected bool $wrapFixtureTransaction = false;
    protected array $ownershipWorkers = [];

    protected function tearDown(): void
    {
        // A contender must stop before cleanup; always invoke the inherited teardown afterwards.
        try {
            foreach ($this->ownershipWorkers as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            if ($this->app) {
                while (DB::connection()->transactionLevel() > 0) {
                    DB::rollBack();
                }
                $this->removeOwnershipFixtures();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function removeOwnershipFixtures(): void
    {
        // Metadata supplies real table names, including future reviewed source/link tables.
        $tables = DB::select("SELECT column_metadata.TABLE_NAME AS table_name
            FROM information_schema.COLUMNS AS column_metadata
            JOIN information_schema.TABLES AS table_metadata
              ON table_metadata.TABLE_SCHEMA = column_metadata.TABLE_SCHEMA
             AND table_metadata.TABLE_NAME = column_metadata.TABLE_NAME
            WHERE column_metadata.TABLE_SCHEMA = DATABASE() AND column_metadata.COLUMN_NAME = 'tenant_id'
              AND table_metadata.TABLE_TYPE = 'BASE TABLE'");
        DB::transaction(function () use ($tables): void {
            // Validate every isolated fixture before deleting across its exact recorded tenant IDs.
            foreach ($this->ownershipFixtures as $fixture) {
                $tenant = $fixture['tenant'];
                // Require both the stored ID and unique fixture marker before removing tenant-owned rows.
                $matches = DB::table('tenants')->where('id', $tenant->id)->where('tenant_code', $tenant->tenant_code)
                    ->where('name', 'like', 'Ownership Test %')->exists();
                if (! $matches) {
                    throw new RuntimeException('Refusing cleanup of an unrecognized Ownership fixture tenant.');
                }
            }
            $tenantIds = array_keys($this->ownershipFixtures);
            $pending = array_map(static fn (object $table): string => $table->table_name, $tables);
            // Restrict retries to FK-blocked parents; this also handles cross-tenant negative-test sources.
            while ($pending !== []) {
                $blocked = [];
                foreach ($pending as $table) {
                    try {
                        DB::table($table)->whereIn('tenant_id', $tenantIds)->delete();
                    } catch (QueryException $exception) {
                        if ((int) ($exception->errorInfo[1] ?? 0) !== 1451) {
                            throw $exception;
                        }
                        $blocked[] = $table;
                    }
                }
                if (count($blocked) === count($pending)) {
                    throw new RuntimeException('Ownership fixture FK cleanup blocked: '.implode(', ', $blocked));
                }
                $pending = $blocked;
            }
            // Untenant-scoped children must cascade or block visibly; never disable foreign keys.
            DB::table('tenants')->whereIn('id', $tenantIds)->delete();
            // Roles/owners are fixture-created global parents tracked immediately during provisioning.
            DB::table('tenant_roles')->whereIn('id', $this->ownershipFixtureRoleIds)->delete();
            DB::table('platform_users')->whereIn('id', $this->ownershipFixtureOwnerIds)->delete();
        });
        // Assert exact fixture IDs are gone rather than relying on a broad name/count query.
        $this->assertSame(0, DB::table('tenants')->whereIn('id', array_keys($this->ownershipFixtures))->count());
        $this->assertSame(0, DB::table('platform_users')->whereIn('id', $this->ownershipFixtureOwnerIds)->count());
    }
}
