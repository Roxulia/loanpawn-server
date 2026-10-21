<?php

namespace Tests\Support;

use LogicException;
use PDO;
use PDOException;

/** Read-only V2 schema checks; never boots Laravel or creates fixtures. */
abstract class DatabaseCheck
{
    protected PDO $connection;
    protected array $tables;

    public function run(): int
    {
        // Reject unsafe destinations before opening a metadata connection.
        try {
            $settings = TestDatabase::settings();
        } catch (LogicException) {
            fwrite(STDERR, "Configure a disposable MySQL database containing 'test' in its name.\n");

            return 2;
        }

        // Keep connection diagnostics free of credentials and raw query details.
        try {
            $this->connect($settings);

            return $this->check();
        } catch (PDOException $exception) {
            fwrite(STDERR, $this->errorMessage($exception));

            return 3;
        }
    }

    abstract protected function check(): int;

    protected function errorMessage(PDOException $exception): string
    {
        // Match the existing Inventory preflight diagnostic.
        return 'Unable to inspect the test database (SQLSTATE '.$exception->getCode()."). Check MySQL service, credentials, and database existence.\n";
    }

    private function connect(array $settings): void
    {
        // Use PDO directly so provider boot cannot trigger application writes.
        $this->connection = new PDO(
            'mysql:host='.($settings['DB_HOST'] ?? '127.0.0.1').';port='.($settings['DB_PORT'] ?? '3306')
                .';dbname='.$settings['DB_DATABASE'].';charset=utf8mb4',
            $settings['DB_USERNAME'] ?? '', $settings['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
        $this->tables = $this->connection->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')
            ->fetchAll(PDO::FETCH_COLUMN);
    }
}
