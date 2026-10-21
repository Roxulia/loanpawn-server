<?php

namespace Tests\Support;

use PDO;

class InventoryDatabaseCheck extends DatabaseCheck
{
    protected function check(): int
    {
        // Report the existing metadata checks in their original order.
        if (! $this->checkTables() || ! $this->checkVersions()) {
            return 2;
        }
        echo "Schema preflight passed; runtime scenarios still require explicit test execution.\n";

        return 0;
    }

    private function checkTables(): bool
    {
        // Require the Inventory baseline without creating or changing tables.
        $required = ['tenants', 'catalog_units', 'inventory_items', 'inventory_locations', 'inventory_movements',
            'inventory_serialized_items', 'inventory_reservations', 'inventory_monthly_checkpoints', 'inventory_monthly_checkpoint_balances'];
        $missing = array_values(array_diff($required, $this->tables));
        echo 'Environment: '.basename(TestDatabase::file())."\n";
        echo 'MySQL: '.$this->connection->getAttribute(PDO::ATTR_SERVER_VERSION)."\n";
        echo 'Required tables missing: '.($missing === [] ? 'none' : implode(', ', $missing))."\n";

        return $missing === [];
    }

    private function checkVersions(): bool
    {
        // Verify optimistic-lock fields on both mutable Inventory records.
        foreach (['inventory_items', 'inventory_locations'] as $table) {
            $columns = $this->connection->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
            if (! in_array('update_key', $columns, true)) {
                echo "Missing update_key on {$table}.\n";

                return false;
            }
        }

        return true;
    }
}
