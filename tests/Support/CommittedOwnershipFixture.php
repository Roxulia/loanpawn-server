<?php

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared committed-fixture lifecycle for V2 Ownership worker and checkpoint cases. */
trait CommittedOwnershipFixture
{
    private array $workers = [];

    protected function tearDown(): void
    {
        // A contender must stop before cleanup; always invoke the inherited teardown afterwards.
        try {
            foreach ($this->workers as $worker) {
                $worker->stop();
            }
            if ($this->app) {
                TestDatabase::rollBack();
                $this->removeFixtures();
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function worker(): OwnershipWorker
    {
        // Keep processes tracked when a negative test switches between fixture tenants.
        $tenantId = (int) $this->ownershipFixture['tenant']->id;

        return $this->workers[$tenantId] ??= new OwnershipWorker($tenantId);
    }

    private function removeFixtures(): void
    {
        // Keep validation and deletion in one transaction over exact fixture IDs.
        $tables = $this->tenantTables();
        DB::transaction(function () use ($tables): void {
            $this->checkFixtures();
            $tenantIds = array_keys($this->ownershipFixtures);
            $this->deleteRows($tables, $tenantIds);

            // Delete tracked global parents last, with foreign keys still enforced.
            DB::table('tenants')->whereIn('id', $tenantIds)->delete();
            DB::table('tenant_roles')->whereIn('id', $this->ownershipFixtureRoleIds)->delete();
            DB::table('platform_users')->whereIn('id', $this->ownershipFixtureOwnerIds)->delete();
        });
        $this->assertCleanup();
    }

    private function tenantTables(): array
    {
        // Metadata supplies real table names, including future reviewed source/link tables.
        $tables = DB::select("SELECT column_metadata.TABLE_NAME AS table_name
            FROM information_schema.COLUMNS AS column_metadata
            JOIN information_schema.TABLES AS table_metadata
              ON table_metadata.TABLE_SCHEMA = column_metadata.TABLE_SCHEMA
             AND table_metadata.TABLE_NAME = column_metadata.TABLE_NAME
            WHERE column_metadata.TABLE_SCHEMA = DATABASE() AND column_metadata.COLUMN_NAME = 'tenant_id'
              AND table_metadata.TABLE_TYPE = 'BASE TABLE'");

        return array_map(static fn (object $table): string => $table->table_name, $tables);
    }

    private function checkFixtures(): void
    {
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
    }

    private function deleteRows(array $tables, array $tenantIds): void
    {
        $pending = $tables;
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
    }

    private function assertCleanup(): void
    {
        // Assert exact fixture IDs are gone rather than relying on a broad name/count query.
        $this->assertSame(0, DB::table('tenants')->whereIn('id', array_keys($this->ownershipFixtures))->count());
        $this->assertSame(0, DB::table('platform_users')->whereIn('id', $this->ownershipFixtureOwnerIds)->count());
    }
}
