<?php

namespace Tests\Support;

use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use LogicException;

class WorkerSetup
{
    public static function boot(string $payload): array
    {
        // Validate the explicit test destination before booting application providers.
        try {
            $settings = TestDatabase::settings();
        } catch (LogicException) {
            exit(10);
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->loadEnvironmentFrom(basename(TestDatabase::file()));
        $app->make(Kernel::class)->bootstrap();
        TestDatabase::connect($settings);
        self::isolateCache();

        // Restore only controlled tenant and clock references from the worker payload.
        $request = json_decode(base64_decode($payload), true, flags: JSON_THROW_ON_ERROR);
        app(TenantContext::class)->set($request['tenant']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($request['now'] ?? '2026-11-02 12:00:00', 'UTC'));

        return $request;
    }

    private static function isolateCache(): void
    {
        // Match the original worker's process-local stores.
        config(['cache.default' => 'array', 'cache.stores.database' => ['driver' => 'array'],
            'cache.stores.redis' => ['driver' => 'array']]);
        app('cache')->forgetDriver('database');
        app('cache')->forgetDriver('redis');
        app('cache')->setDefaultDriver('array');
    }
}
