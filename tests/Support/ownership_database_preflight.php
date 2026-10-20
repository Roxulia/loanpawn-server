<?php

// Reuse disposable-MySQL selection and Inventory metadata checks without booting Laravel.
echo "Inventory prerequisite checks:\n";
require __DIR__.'/inventory_database_preflight.php';

// The required preflight supplies its validated PDO connection; never print connection secrets.
echo "Ownership metadata checks:\n";
try {
    // Inspect the existing Ownership baseline in one metadata read, without creating fixtures.
    $ownershipColumns = $connection->query(
        "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('owned_items', 'acquisition_lots', 'ownership_movements')"
    )->fetchAll(PDO::FETCH_ASSOC);
    $ownershipColumnTypes = [];
    foreach ($ownershipColumns as $column) {
        $ownershipColumnTypes[$column['TABLE_NAME']][$column['COLUMN_NAME']] = strtolower($column['COLUMN_TYPE']);
    }

    // Check only established baseline fields; future constraints remain a separate schema dependency.
    $ownershipBaseline = [
        'owned_items' => ['id', 'tenant_id', 'code', 'inventory_item_id', 'lifecycle_status'],
        'acquisition_lots' => ['id', 'tenant_id', 'code', 'owned_item_id', 'source_module', 'source_type',
            'source_code', 'acquired_at', 'acquired_quantity', 'unit_cost_basis', 'estimated_unit_value', 'currency_code'],
        'ownership_movements' => ['id', 'tenant_id', 'code', 'owned_item_id', 'acquisition_lot_id',
            'quantity', 'owned_delta', 'reserved_delta', 'pledged_delta', 'source_module', 'source_type',
            'source_code', 'idempotency_record_id', 'occurred_at', 'created_at'],
    ];
    $ownershipMissingBaseline = [];
    foreach ($ownershipBaseline as $table => $columns) {
        foreach ($columns as $column) {
            if (! isset($ownershipColumnTypes[$table][$column])) {
                $ownershipMissingBaseline[] = $table.'.'.$column;
            }
        }
    }
    echo 'Missing baseline fields: '.($ownershipMissingBaseline === [] ? 'none' : implode(', ', $ownershipMissingBaseline))."\n";

    // Confirm exact baseline decimal types instead of accepting a float-backed schema.
    $ownershipExpectedDecimals = [
        'acquisition_lots' => ['acquired_quantity' => 'decimal(14,3)', 'unit_cost_basis' => 'decimal(18,4)',
            'estimated_unit_value' => 'decimal(18,4)'],
        'ownership_movements' => ['quantity' => 'decimal(14,3)', 'owned_delta' => 'decimal(14,3)',
            'pledged_delta' => 'decimal(14,3)', 'reserved_delta' => 'decimal(15,3)'],
    ];
    $ownershipUnexpectedDecimals = [];
    foreach ($ownershipExpectedDecimals as $table => $columns) {
        foreach ($columns as $column => $expectedType) {
            if (isset($ownershipColumnTypes[$table][$column]) && $ownershipColumnTypes[$table][$column] !== $expectedType) {
                $ownershipUnexpectedDecimals[] = $table.'.'.$column.' (expected '.$expectedType.')';
            }
        }
    }
    echo 'Unexpected baseline decimal types: '.($ownershipUnexpectedDecimals === [] ? 'none' : implode(', ', $ownershipUnexpectedDecimals))."\n";

    // Check transactional engines and unique-key columns without relying on index names.
    $ownershipEngines = $connection->query(
        "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('owned_items', 'acquisition_lots', 'ownership_movements')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $ownershipUnexpectedEngines = [];
    foreach ($ownershipBaseline as $table => $columns) {
        if (isset($ownershipEngines[$table]) && strcasecmp($ownershipEngines[$table], 'InnoDB') !== 0) {
            $ownershipUnexpectedEngines[] = $table;
        }
    }
    echo 'Non-InnoDB Ownership tables: '.($ownershipUnexpectedEngines === [] ? 'none' : implode(', ', $ownershipUnexpectedEngines))."\n";

    $ownershipUniqueRows = $connection->query(
        "SELECT TABLE_NAME, INDEX_NAME,
                GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS key_columns
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0
           AND TABLE_NAME IN ('owned_items', 'acquisition_lots', 'ownership_movements')
         GROUP BY TABLE_NAME, INDEX_NAME"
    )->fetchAll(PDO::FETCH_ASSOC);
    $ownershipUniqueKeys = [];
    foreach ($ownershipUniqueRows as $index) {
        $ownershipUniqueKeys[$index['TABLE_NAME']][] = $index['key_columns'];
    }
    $ownershipRequiredUniqueKeys = [
        'owned_items' => ['tenant_id,code', 'tenant_id,inventory_item_id'],
        'acquisition_lots' => ['tenant_id,code', 'tenant_id,source_module,source_type,source_code'],
        'ownership_movements' => ['tenant_id,code', 'tenant_id,idempotency_record_id'],
    ];
    $ownershipMissingUniqueKeys = [];
    foreach ($ownershipRequiredUniqueKeys as $table => $keys) {
        foreach ($keys as $key) {
            if (! in_array($key, $ownershipUniqueKeys[$table] ?? [], true)) {
                $ownershipMissingUniqueKeys[] = $table.'('.$key.')';
            }
        }
    }
    echo 'Missing baseline unique keys: '.($ownershipMissingUniqueKeys === [] ? 'none' : implode(', ', $ownershipMissingUniqueKeys))."\n";

    // Report agreed future names separately; presence alone does not certify their unreviewed shape.
    $ownershipPlannedTables = ['ownership_receipt_links', 'ownership_monthly_checkpoints', 'ownership_monthly_checkpoint_balances'];
    $ownershipMissingPlannedTables = array_values(array_diff($ownershipPlannedTables, $tables));
    echo 'Missing planned Part 2 tables: '.($ownershipMissingPlannedTables === [] ? 'none' : implode(', ', $ownershipMissingPlannedTables))."\n";
    echo 'Ownership update_key: '.(isset($ownershipColumnTypes['owned_items']['update_key']) ? 'present; definition awaits OWN-15 review' : 'pending schema')."\n";

    // An incomplete baseline is a setup blocker, not a feature-test assertion failure.
    if ($ownershipMissingBaseline !== [] || $ownershipUnexpectedDecimals !== []
        || $ownershipUnexpectedEngines !== [] || $ownershipMissingUniqueKeys !== []) {
        echo "Ownership baseline is not ready. No migrations or writes were performed.\n";
        exit(2);
    }

    // Do not announce full readiness before membership/commitment/checkpoint schema is reviewed.
    echo "Existing Ownership baseline checks passed.\n";
    echo "Part 2 readiness remains pending OWN-15 schema/constraint review: receipt allocation, single-current piece membership, source commitments, update_key, and monthly checkpoints.\n";
    echo "Update the future-schema checks after that review; no migrations, fixtures, or feature tests were run.\n";
    exit(2);
} catch (PDOException $exception) {
    // Connection/query error text may contain sensitive details; expose only the SQLSTATE.
    fwrite(STDERR, 'Unable to inspect Ownership metadata (SQLSTATE '.$exception->getCode().").\n");
    exit(3);
}
