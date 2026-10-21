<?php

namespace Tests\Support;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Uses an explicitly configured, already migrated test database; never refreshes schema. */
abstract class MySqlTestCase extends TestCase
{
    protected bool $wrapFixtureTransaction = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Select the disposable connection before isolating runtime stores.
        TestDatabase::connect(TestDatabase::settings());
        $this->isolateCache();
        $this->assertSame('mysql', DB::connection()->getDriverName());
        if ($this->wrapFixtureTransaction) {
            DB::beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        // Always release framework state after rolling back fixture work.
        try {
            if ($this->app) {
                TestDatabase::rollBack();
                app(TenantContext::class)->clear();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function isolateCache(): void
    {
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
    }
}
