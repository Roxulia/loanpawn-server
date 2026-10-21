<?php

namespace Tests\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/** Reuses the shared disposable MySQL connection and rollback-only test boundary. */
abstract class OwnershipMySqlTestCase extends MySqlTestCase
{
    use OwnershipFixture;

    protected function setUp(): void
    {
        // Select the guarded test connection before provisioning any fixture records.
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'));
        $this->provisionOwnershipTenant();
    }

    protected function tearDown(): void
    {
        // Restore process-local auth/clocks even when a test or fixture assertion fails.
        try {
            if ($this->app) {
                Auth::guard('tenantuser')->forgetUser();
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            parent::tearDown();
        }
    }
}
