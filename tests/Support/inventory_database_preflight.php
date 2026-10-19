<?php

// Read-only preflight: never loads application providers, runs migrations, or writes fixtures.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$environmentFile = is_file($root.'/.env.test') ? $root.'/.env.test' : $root.'/.env.testing';
$environment = Dotenv\Dotenv::parse(file_get_contents($environmentFile));
$database = $environment['DB_DATABASE'] ?? '';
if (($environment['DB_CONNECTION'] ?? '') !== 'mysql' || ! str_contains(strtolower($database), 'test')) {
    fwrite(STDERR, "Configure a disposable MySQL database containing 'test' in its name.\n");
    exit(2);
}

// Inspect metadata only; credentials and connection error details are never printed.
try {
    $connection = new PDO(
        'mysql:host='.($environment['DB_HOST'] ?? '127.0.0.1').';port='.($environment['DB_PORT'] ?? '3306').';dbname='.$database.';charset=utf8mb4',
        $environment['DB_USERNAME'] ?? '', $environment['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
    );
    $required = ['tenants', 'catalog_units', 'inventory_items', 'inventory_locations', 'inventory_movements', 'inventory_serialized_items', 'inventory_reservations', 'inventory_monthly_checkpoints', 'inventory_monthly_checkpoint_balances'];
    $tables = $connection->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($required, $tables));
    echo 'Environment: '.basename($environmentFile)."\n";
    echo 'MySQL: '.$connection->getAttribute(PDO::ATTR_SERVER_VERSION)."\n";
    echo 'Required tables missing: '.($missing === [] ? 'none' : implode(', ', $missing))."\n";
    if ($missing !== []) exit(2);
    foreach (['inventory_items', 'inventory_locations'] as $table) {
        $columns = $connection->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
        if (! in_array('update_key', $columns, true)) {
            echo "Missing update_key on {$table}.\n";
            exit(2);
        }
    }
    echo "Schema preflight passed; runtime scenarios still require explicit test execution.\n";
} catch (PDOException $exception) {
    fwrite(STDERR, 'Unable to inspect the test database (SQLSTATE '.$exception->getCode()."). Check MySQL service, credentials, and database existence.\n");
    exit(3);
}
