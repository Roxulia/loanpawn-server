<?php

namespace Tests\Support;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Shared disposable-database selection for V2 tests, workers and read-only checks. */
class TestDatabase
{
    public static function file(): string
    {
        // Prefer the explicit test override without reading the application's normal environment.
        $root = dirname(__DIR__, 2);

        return is_file($root.'/.env.test') ? $root.'/.env.test' : $root.'/.env.testing';
    }

    public static function settings(): array
    {
        // Validate the destination before any application database access.
        $settings = Dotenv::parse(file_get_contents(self::file()));
        if (($settings['DB_CONNECTION'] ?? '') !== 'mysql'
            || ! str_contains(strtolower($settings['DB_DATABASE'] ?? ''), 'test')) {
            throw new LogicException('Configure an explicitly disposable MySQL database in .env.test or .env.testing.');
        }

        return $settings;
    }

    public static function connect(array $settings): void
    {
        // Preserve the established connection name for existing tests and worker scripts.
        config(['database.connections.inventory_testing' => array_replace(config('database.connections.mysql'), [
            'url' => null,
            'host' => $settings['DB_HOST'] ?? '127.0.0.1',
            'port' => $settings['DB_PORT'] ?? '3306',
            'database' => $settings['DB_DATABASE'],
            'username' => $settings['DB_USERNAME'] ?? '',
            'password' => $settings['DB_PASSWORD'] ?? '',
            'unix_socket' => $settings['DB_SOCKET'] ?? '',
        ])]);
        DB::setDefaultConnection('inventory_testing');
    }

    public static function rollBack(): void
    {
        // Unwind nested operations before fixture cleanup or returning a failed worker result.
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
    }
}
