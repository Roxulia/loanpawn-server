<?php

namespace Tests\Support;

use PDO;

/** Read-only OWN-15 metadata verification; never migrates, seeds, or inserts fixtures. */
class OwnershipStructureCheck extends DatabaseCheck
{
    protected function check(): int
    {
        // Inspect the actual disposable database, independently of Laravel model declarations.
        $errors = [];
        $definitions = $this->definitions();
        $columns = [];
        foreach ($this->connection->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $row) {
            $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
        }
        $engines = $this->connection->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($definitions as $table => $fields) {
            // Missing tables and incorrect decimal storage are schema failures, not skipped coverage.
            if (($engines[$table] ?? '') !== 'InnoDB') {
                $errors[] = $table.' missing or not InnoDB';
            }
            foreach ($fields as $field => $type) {
                if (strtolower($columns[$table][$field]['COLUMN_TYPE'] ?? '') !== $type) {
                    $errors[] = $table.'.'.$field.' expected '.$type;
                }
            }
        }

        // Compare key columns and uniqueness rather than relying on generated index names.
        $indexes = [];
        foreach ($this->connection->query("SELECT TABLE_NAME, NON_UNIQUE, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS fields FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE") as $row) {
            $indexes[$row['TABLE_NAME']][(int) $row['NON_UNIQUE']][] = $row['fields'];
        }
        foreach ($this->keys() as [$table, $unique, $fields]) {
            if (! in_array($fields, $indexes[$table][$unique ? 0 : 1] ?? [], true)) {
                $errors[] = $table.' missing '.($unique ? 'unique ' : '').'index '.$fields;
            }
        }

        // Generated keys must be database-computed and tied to open membership episodes.
        foreach (['ownership_piece_memberships' => ['current_piece_id', 'inventory_serialized_item_id'],
            'ownership_piece_commitments' => ['current_membership_id', 'ownership_piece_membership_id']] as $table => [$key, $source]) {
            $expression = strtolower($columns[$table][$key]['GENERATION_EXPRESSION'] ?? '');
            if (! str_contains($expression, 'ended_at') || ! str_contains($expression, $source)
                || ! str_contains($expression, 'is null') || ! str_contains($expression, 'else null')) {
                $errors[] = $table.'.'.$key.' missing expected open-episode expression';
            }
        }
        if (($columns['owned_items']['update_key']['COLUMN_DEFAULT'] ?? null) !== '0'
            || ($columns['owned_items']['update_key']['IS_NULLABLE'] ?? '') !== 'NO') {
            $errors[] = 'owned_items.update_key must be non-null with default zero';
        }

        // Verify every new stored parent reference points to the intended table with its deletion policy.
        $foreign = [];
        foreach ($this->connection->query('SELECT k.TABLE_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL') as $row) {
            $foreign[$row['TABLE_NAME']][$row['COLUMN_NAME']] = [$row['REFERENCED_TABLE_NAME'], $row['DELETE_RULE']];
        }
        foreach ($this->references() as $table => $fields) {
            foreach ($fields as $field => [$parent, $rule]) {
                if (($foreign[$table][$field] ?? null) !== [$parent, $rule]) {
                    $errors[] = $table.'.'.$field.' foreign key mismatch';
                }
            }
        }

        echo 'Database: '.$this->connection->query('SELECT DATABASE()')->fetchColumn()."\n";
        echo 'MySQL: '.$this->connection->getAttribute(PDO::ATTR_SERVER_VERSION)."\n";
        echo 'OWN-15 tables inspected: '.count($definitions)."\n";
        foreach ($errors as $error) {
            echo 'FAIL: '.$error."\n";
        }
        echo $errors === [] ? "OWN-15 structure passed: fields, types, engines, indexes, foreign keys, generated expressions, version default.\n" : "OWN-15 structure failed.\n";
        echo "Metadata only; runtime quantity, uniqueness contention, and rollback scenarios are not tested.\n";

        return $errors === [] ? 0 : 2;
    }

    private function definitions(): array
    {
        // Keep expected storage types literal and independent of migration execution.
        $identity = ['id' => 'bigint unsigned', 'tenant_id' => 'bigint unsigned'];
        $lot = $identity + ['owned_item_id' => 'bigint unsigned', 'acquisition_lot_id' => 'bigint unsigned'];
        $quantities = ['owned_quantity' => 'decimal(24,3)', 'reserved_quantity' => 'decimal(24,3)', 'pledged_quantity' => 'decimal(24,3)'];

        return [
            'owned_items' => $identity + ['update_key' => 'bigint unsigned'],
            'acquisition_lots' => $identity + ['source_module' => 'varchar(40)', 'source_type' => 'varchar(120)', 'source_code' => 'varchar(120)', 'acquired_at' => 'date', 'acquired_quantity' => 'decimal(14,3)', 'unit_cost_basis' => 'decimal(18,4)', 'estimated_unit_value' => 'decimal(18,4)'],
            'ownership_receipt_links' => $lot + ['code' => 'varchar(40)', 'purchase_receipt_line_id' => 'bigint unsigned', 'linked_quantity' => 'decimal(14,3)', 'remaining_owned_quantity' => 'decimal(14,3)', 'idempotency_record_id' => 'bigint unsigned'],
            'ownership_piece_memberships' => $lot + ['ownership_receipt_link_id' => 'bigint unsigned', 'inventory_serialized_item_id' => 'bigint unsigned', 'current_piece_id' => 'bigint unsigned', 'started_at' => 'datetime(6)', 'ended_at' => 'datetime(6)'],
            'ownership_commitments' => $lot + ['kind' => 'varchar(16)', 'source_type' => 'varchar(120)', 'source_code' => 'varchar(120)', 'quantity' => 'decimal(14,3)', 'remaining_quantity' => 'decimal(14,3)'],
            'ownership_piece_commitments' => $identity + ['ownership_commitment_id' => 'bigint unsigned', 'ownership_piece_membership_id' => 'bigint unsigned', 'current_membership_id' => 'bigint unsigned', 'ended_at' => 'datetime(6)'],
            'ownership_monthly_checkpoints' => $identity + ['owned_item_id' => 'bigint unsigned', 'month' => 'date', 'timezone' => 'varchar(64)', 'cutoff_at' => 'datetime(6)', 'movement_watermark' => 'bigint unsigned', 'completed_at' => 'datetime(6)'] + $quantities,
            'ownership_monthly_checkpoint_balances' => $identity + ['ownership_monthly_checkpoint_id' => 'bigint unsigned', 'acquisition_lot_id' => 'bigint unsigned'] + $quantities,
        ];
    }

    private function keys(): array
    {
        // Required uniqueness and tail/source lookup paths; optimizer performance remains separate.
        return [
            ['owned_items', true, 'tenant_id,inventory_item_id'],
            ['acquisition_lots', true, 'tenant_id,source_module,source_type,source_code'],
            ['ownership_receipt_links', true, 'tenant_id,code'],
            ['ownership_receipt_links', true, 'tenant_id,idempotency_record_id'],
            ['ownership_receipt_links', false, 'tenant_id,purchase_receipt_line_id,id'],
            ['ownership_receipt_links', false, 'tenant_id,acquisition_lot_id,id'],
            ['ownership_piece_memberships', true, 'current_piece_id'],
            ['ownership_piece_memberships', false, 'tenant_id,acquisition_lot_id,ended_at,id'],
            ['ownership_piece_memberships', false, 'tenant_id,inventory_serialized_item_id,id'],
            ['ownership_commitments', true, 'tenant_id,acquisition_lot_id,kind,source_type,source_code'],
            ['ownership_commitments', false, 'tenant_id,source_type,source_code,id'],
            ['ownership_piece_commitments', true, 'current_membership_id'],
            ['ownership_piece_commitments', false, 'tenant_id,ownership_commitment_id,id'],
            ['ownership_monthly_checkpoints', true, 'tenant_id,owned_item_id,month'],
            ['ownership_monthly_checkpoint_balances', true, 'ownership_monthly_checkpoint_id,acquisition_lot_id'],
            ['ownership_movements', false, 'tenant_id,owned_item_id,created_at,id'],
            ['ownership_movements', false, 'tenant_id,owned_item_id,id'],
        ];
    }

    private function references(): array
    {
        // Match the project's single-column FK convention; cross-tenant associations need service validation.
        $tenant = ['tenant_id' => ['tenants', 'CASCADE']];
        $lot = $tenant + ['owned_item_id' => ['owned_items', 'RESTRICT'], 'acquisition_lot_id' => ['acquisition_lots', 'RESTRICT']];

        return [
            'ownership_receipt_links' => $lot + ['purchase_receipt_line_id' => ['purchase_receipt_lines', 'RESTRICT']],
            'ownership_piece_memberships' => $lot + ['ownership_receipt_link_id' => ['ownership_receipt_links', 'RESTRICT'], 'inventory_serialized_item_id' => ['inventory_serialized_items', 'RESTRICT']],
            'ownership_commitments' => $lot,
            'ownership_piece_commitments' => $tenant + ['ownership_commitment_id' => ['ownership_commitments', 'RESTRICT'], 'ownership_piece_membership_id' => ['ownership_piece_memberships', 'RESTRICT']],
            'ownership_monthly_checkpoints' => $tenant + ['owned_item_id' => ['owned_items', 'RESTRICT']],
            'ownership_monthly_checkpoint_balances' => $tenant + ['ownership_monthly_checkpoint_id' => ['ownership_monthly_checkpoints', 'CASCADE'], 'acquisition_lot_id' => ['acquisition_lots', 'RESTRICT']],
        ];
    }
}
