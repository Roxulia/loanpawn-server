<?php

namespace Tests\Support;

use PDO;
use PDOException;

/** Ownership requires the Inventory baseline before inspecting its own metadata. */
class OwnershipDatabaseCheck extends InventoryDatabaseCheck
{
    private bool $checkingOwnership = false;

    public function run(): int
    {
        // Keep prerequisite output and all existing readiness exit codes.
        echo "Inventory prerequisite checks:\n";

        return parent::run();
    }

    protected function check(): int
    {
        // Finish Inventory checks on the same explicit PDO connection first.
        $result = parent::check();
        if ($result !== 0) {
            return $result;
        }
        $this->checkingOwnership = true;
        echo "Ownership metadata checks:\n";
        $columns = $this->readColumns();
        $fieldsReady = $this->checkFields($columns);
        $decimalsReady = $this->checkDecimals($columns);
        $enginesReady = $this->checkEngines();
        $keysReady = $this->checkKeys();
        $this->reportPlannedTables($columns);

        // Preserve the pending-schema gate even when the existing baseline passes.
        if (! $fieldsReady || ! $decimalsReady || ! $enginesReady || ! $keysReady) {
            echo "Ownership baseline is not ready. No migrations or writes were performed.\n";

            return 2;
        }

        return $this->reportPendingSchema();
    }

    protected function errorMessage(PDOException $exception): string
    {
        // Inventory prerequisite failures retain their own diagnostic.
        return $this->checkingOwnership
            ? 'Unable to inspect Ownership metadata (SQLSTATE '.$exception->getCode().").\n"
            : parent::errorMessage($exception);
    }

    private function readColumns(): array
    {
        // Inspect the existing Ownership baseline in one metadata read, without creating fixtures.
        $rows = $this->connection->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('owned_items', 'acquisition_lots', 'ownership_movements')"
        )->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        foreach ($rows as $column) {
            $columns[$column['TABLE_NAME']][$column['COLUMN_NAME']] = strtolower($column['COLUMN_TYPE']);
        }

        return $columns;
    }

    private function checkFields(array $columns): bool
    {
        // Check only established baseline fields; future constraints remain a separate schema dependency.
        $required = [
            'owned_items' => ['id', 'tenant_id', 'code', 'inventory_item_id', 'lifecycle_status'],
            'acquisition_lots' => ['id', 'tenant_id', 'code', 'owned_item_id', 'source_module', 'source_type',
                'source_code', 'acquired_at', 'acquired_quantity', 'unit_cost_basis', 'estimated_unit_value', 'currency_code'],
            'ownership_movements' => ['id', 'tenant_id', 'code', 'owned_item_id', 'acquisition_lot_id',
                'quantity', 'owned_delta', 'reserved_delta', 'pledged_delta', 'source_module', 'source_type',
                'source_code', 'idempotency_record_id', 'occurred_at', 'created_at'],
        ];
        $missing = [];
        foreach ($required as $table => $fields) {
            foreach ($fields as $column) {
                if (! isset($columns[$table][$column])) {
                    $missing[] = $table.'.'.$column;
                }
            }
        }
        echo 'Missing baseline fields: '.($missing === [] ? 'none' : implode(', ', $missing))."\n";

        return $missing === [];
    }

    private function checkDecimals(array $columns): bool
    {
        // Confirm exact baseline decimal types instead of accepting a float-backed schema.
        $expected = [
            'acquisition_lots' => ['acquired_quantity' => 'decimal(14,3)', 'unit_cost_basis' => 'decimal(18,4)',
                'estimated_unit_value' => 'decimal(18,4)'],
            'ownership_movements' => ['quantity' => 'decimal(14,3)', 'owned_delta' => 'decimal(14,3)',
                'pledged_delta' => 'decimal(14,3)', 'reserved_delta' => 'decimal(15,3)'],
        ];
        $invalid = [];
        foreach ($expected as $table => $fields) {
            foreach ($fields as $column => $expectedType) {
                if (isset($columns[$table][$column]) && $columns[$table][$column] !== $expectedType) {
                    $invalid[] = $table.'.'.$column.' (expected '.$expectedType.')';
                }
            }
        }
        echo 'Unexpected baseline decimal types: '.($invalid === [] ? 'none' : implode(', ', $invalid))."\n";

        return $invalid === [];
    }

    private function checkEngines(): bool
    {
        // Check transactional engines and unique-key columns without relying on index names.
        $engines = $this->connection->query(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME IN ('owned_items', 'acquisition_lots', 'ownership_movements')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $invalid = [];
        foreach (['owned_items', 'acquisition_lots', 'ownership_movements'] as $table) {
            if (isset($engines[$table]) && strcasecmp($engines[$table], 'InnoDB') !== 0) {
                $invalid[] = $table;
            }
        }
        echo 'Non-InnoDB Ownership tables: '.($invalid === [] ? 'none' : implode(', ', $invalid))."\n";

        return $invalid === [];
    }

    private function checkKeys(): bool
    {
        // Compare key columns instead of relying on generated index names.
        $rows = $this->connection->query(
            "SELECT TABLE_NAME, INDEX_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS key_columns
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
               AND TABLE_NAME IN ('owned_items', 'acquisition_lots', 'ownership_movements')
             GROUP BY TABLE_NAME, INDEX_NAME"
        )->fetchAll(PDO::FETCH_ASSOC);
        $keys = [];
        foreach ($rows as $index) {
            $keys[$index['TABLE_NAME']][] = $index['key_columns'];
        }
        $required = [
            'owned_items' => ['tenant_id,code', 'tenant_id,inventory_item_id'],
            'acquisition_lots' => ['tenant_id,code', 'tenant_id,source_module,source_type,source_code'],
            'ownership_movements' => ['tenant_id,code', 'tenant_id,idempotency_record_id'],
        ];
        $missing = [];
        foreach ($required as $table => $requiredKeys) {
            foreach ($requiredKeys as $key) {
                if (! in_array($key, $keys[$table] ?? [], true)) {
                    $missing[] = $table.'('.$key.')';
                }
            }
        }
        echo 'Missing baseline unique keys: '.($missing === [] ? 'none' : implode(', ', $missing))."\n";

        return $missing === [];
    }

    private function reportPlannedTables(array $columns): void
    {
        // Report agreed future names separately; presence alone does not certify their unreviewed shape.
        $planned = ['ownership_receipt_links', 'ownership_monthly_checkpoints', 'ownership_monthly_checkpoint_balances'];
        $missing = array_values(array_diff($planned, $this->tables));
        echo 'Missing planned Part 2 tables: '.($missing === [] ? 'none' : implode(', ', $missing))."\n";
        echo 'Ownership update_key: '.(isset($columns['owned_items']['update_key']) ? 'present; definition awaits OWN-15 review' : 'pending schema')."\n";
    }

    private function reportPendingSchema(): int
    {
        // Do not announce full readiness before membership/commitment/checkpoint schema is reviewed.
        echo "Existing Ownership baseline checks passed.\n";
        echo "Part 2 readiness remains pending OWN-15 schema/constraint review: receipt allocation, single-current piece membership, source commitments, update_key, and monthly checkpoints.\n";
        echo "Update the future-schema checks after that review; no migrations, fixtures, or feature tests were run.\n";
        return 2;
    }

}
