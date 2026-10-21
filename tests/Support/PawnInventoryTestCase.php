<?php

namespace Tests\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/** Shared lifecycle for V2 Pawn custody and Inventory verification tests. */
abstract class PawnInventoryTestCase extends InventoryMySqlTestCase
{
    use PawnInventoryFixture;

    protected function setUp(): void
    {
        // Start the guarded transaction before creating the Pawn fixture.
        parent::setUp();
        $this->createTenant();
    }

    protected function tearDown(): void
    {
        // Restore authentication and both clocks even when fixture cleanup fails.
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
