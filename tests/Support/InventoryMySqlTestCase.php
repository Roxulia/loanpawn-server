<?php

namespace Tests\Support;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Uses an explicitly configured, already migrated test database; never refreshes schema. */
abstract class InventoryMySqlTestCase extends TestCase
{
    protected bool $wrapFixtureTransaction = true;
    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        $file = is_file($root.'/.env.test') ? $root.'/.env.test' : $root.'/.env.testing';
        $environment = \Dotenv\Dotenv::parse(file_get_contents($file));
        $database = $environment['DB_DATABASE'] ?? '';
        if (($environment['DB_CONNECTION'] ?? '') !== 'mysql' || ! str_contains(strtolower($database), 'test')) {
            $this->fail('Configure an explicitly disposable MySQL database in .env.test or .env.testing.');
        }
        // Override PHPUnit's SQLite default without changing shared environment/config files.
        config(['database.connections.inventory_testing' => array_replace(config('database.connections.mysql'), [
            'url' => null, 'host' => $environment['DB_HOST'] ?? '127.0.0.1',
            'port' => $environment['DB_PORT'] ?? '3306', 'database' => $database,
            'username' => $environment['DB_USERNAME'] ?? '', 'password' => $environment['DB_PASSWORD'] ?? '',
            'unix_socket' => $environment['DB_SOCKET'] ?? '',
        ])]);
        DB::setDefaultConnection('inventory_testing');
        // Runtime fallbacks may select database cache during boot; keep test cache process-local.
        config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
        // Tenant cache selection can switch drivers dynamically; isolate both fallback names.
        config(['cache.stores.database' => ['driver' => 'array', 'serialize' => false],
            'cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
        app('cache')->forgetDriver('database');
        app('cache')->forgetDriver('redis');
        app('cache')->setDefaultDriver('array');
        // HTTP throttling may retain the store resolved during framework bootstrap.
        config(['cache.limiter' => 'array']);
        app()->instance('cache.store', app('cache')->store('array'));
        // Preserve registered limit definitions while replacing only their bootstrap cache store.
        $limiter = app(\Illuminate\Cache\RateLimiter::class);
        (new \ReflectionProperty($limiter, 'cache'))->setValue($limiter, app('cache')->store('array'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        if ($this->wrapFixtureTransaction) DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        // Every fixture and operation stays inside the test's owning transaction.
        if ($this->app) {
            while (DB::connection()->transactionLevel() > 0) DB::rollBack();
            app(TenantContext::class)->clear();
        }
        parent::tearDown();
    }
}
