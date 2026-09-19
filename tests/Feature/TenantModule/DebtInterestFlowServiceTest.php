<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\TenantDebt;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\DebtInterestFlowService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtInterestFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_interest_starts_at_creation_and_future_rows_use_remaining_principal(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');
        $owner = PlatformUser::query()->create([
            'code' => 'OWNER-INTEREST', 'name' => 'Owner', 'email' => 'interest-owner@example.com',
            'phone' => '09111111111', 'password' => 'secret123', 'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $owner->id, 'name' => 'Interest Tenant', 'tenant_code' => 'interest-tenant',
            'subdomain' => 'interest-tenant', 'status' => 'active',
        ]);
        app(TenantContext::class)->set($tenant);
        $type = InterestType::query()->create([
            'tenant_id' => null, 'code' => 'monthly', 'name' => 'Monthly', 'duration_in_days' => 30, 'is_default' => true,
        ]);
        $debt = TenantDebt::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'DEBT-INTEREST-1', 'amount' => 1000,
            'principal_balance' => 1000, 'description' => 'Interest debt', 'apply_interest' => true,
            'interest_rate' => 10, 'interest_type_id' => $type->id, 'interest_anchor_at' => now(), 'is_paid' => false,
        ]);

        $service = app(DebtInterestFlowService::class);
        $service->initialize($debt);
        $first = $service->calculate($debt->id);

        $this->assertSame('100.00', $first->outstandingInterest);
        $period = $first->interestBreakdown[0];
        $timezone = $period['period_timezone'];
        $this->assertNotEmpty($timezone);
        $this->assertSame(
            '2026-01-15 00:00:00',
            CarbonImmutable::parse($period['start_period_at'])->setTimezone($timezone)->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-02-14 23:59:59',
            CarbonImmutable::parse($period['end_period_at'])->setTimezone($timezone)->format('Y-m-d H:i:s'),
        );

        $row = $debt->interestAccruals()->firstOrFail();
        $row->update(['paid_amount' => 100, 'is_paid' => true]);
        $debt->update(['principal_balance' => 600, 'last_interest_paid_at' => now(), 'interest_anchor_at' => now()]);
        Carbon::setTestNow('2026-02-15 10:00:00');

        $next = $service->calculate($debt->id);
        $this->assertSame(60.0, $next->interestBreakdown[1]['interest_amount']);
    }
}
