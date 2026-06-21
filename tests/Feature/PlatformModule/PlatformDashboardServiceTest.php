<?php

namespace Tests\Feature\PlatformModule;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Services\PlatformModule\PlatformDashboardService;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-20 10:00:00');
        $this->seed(PackageSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_platform_dashboard_summary_is_scoped_and_aggregated(): void
    {
        $owner = $this->platformUser('owner@example.com');
        $otherOwner = $this->platformUser('other@example.com');
        $healthyTenant = $this->tenant($owner, 'healthy-tenant', 'Healthy Tenant');
        $riskTenant = $this->tenant($owner, 'risk-tenant', 'Risk Tenant');
        $otherTenant = $this->tenant($otherOwner, 'other-tenant', 'Other Tenant');

        $this->license($healthyTenant, 'basic', 'active', [
            'current_month_slip_count' => 40,
            'current_staff_count' => 2,
        ]);
        $this->license($riskTenant, 'trial', 'expired', [
            'current_month_slip_count' => 30,
            'current_staff_count' => 2,
        ]);
        $this->license($otherTenant, 'premium', 'active', [
            'current_month_slip_count' => 999,
            'current_staff_count' => 99,
        ]);

        $this->contact($healthyTenant, 'Yangon', 'Myanmar');
        $this->contact($riskTenant, 'Mandalay', 'Myanmar');
        $this->contact($otherTenant, 'Bangkok', 'Thailand');

        $this->accounting($healthyTenant, 'incoming', 1000, 'Redeem payment', now());
        $this->accounting($healthyTenant, 'outgoing', 200, 'Shop expense', now());
        $this->accounting($healthyTenant, 'incoming', 600, 'Interest payment', now()->subDays(2));
        $this->accounting($healthyTenant, 'incoming', 400, 'Previous income', now()->subMonthNoOverflow());

        $this->accounting($riskTenant, 'incoming', 50, 'Small income', now());
        $this->accounting($riskTenant, 'outgoing', 500, 'Large expense', now());
        $this->accounting($riskTenant, 'outgoing', 700, 'Month expense', now()->subDays(3));
        $this->debt($riskTenant, 300);

        $this->accounting($otherTenant, 'incoming', 9000, 'Other owner income', now());
        $this->activeSlipWithCollateral($healthyTenant, 10000);
        $this->activeSlipWithCollateral($otherTenant, 99999);

        $this->actingAs($owner, 'platformuser');

        $summary = app(PlatformDashboardService::class)->getSummary();

        $this->assertTrue($summary['has_data']);
        $this->assertSame(DashboardTimeFilter::THIS_MONTH, $summary['filters']['timeFilter']);
        $this->assertSame(2, $summary['tenant_counts']['total']);
        $this->assertSame(1, $summary['tenant_counts']['expired']);
        $this->assertSame(1, $summary['plan_breakdown']['basic']);
        $this->assertSame(1, $summary['plan_breakdown']['trial']);
        $this->assertSame(0, $summary['plan_breakdown']['premium']);

        $this->assertSame(1050.0, $summary['financial']['todayIncome']);
        $this->assertSame(700.0, $summary['financial']['todayExpense']);
        $this->assertSame(350.0, $summary['financial']['todayNet']);
        $this->assertSame(1650.0, $summary['financial']['monthIncome']);
        $this->assertSame(1400.0, $summary['financial']['monthExpense']);
        $this->assertSame(250.0, $summary['financial']['monthNet']);
        $this->assertSame(1650.0, $summary['financial']['periodIncome']);
        $this->assertSame(1400.0, $summary['financial']['periodExpense']);
        $this->assertSame(250.0, $summary['financial']['periodNet']);
        $this->assertSame(250.0, $summary['financial']['realizedNetworth']);
        $this->assertSame(10000.0, $summary['financial']['activeCollateralMinimumRetailPrice']);
        $this->assertSame(10250.0, $summary['financial']['unrealizedNetworth']);

        $this->assertSame(70, $summary['package_usage']['currentMonthSlipCount']);
        $this->assertSame(4, $summary['package_usage']['currentStaffCount']);
        $this->assertSame('Risk Tenant', $summary['risk_tenants'][0]['name']);
        $this->assertSame('critical', $summary['risk_tenants'][0]['riskLabel']);
        $this->assertSame('Healthy Tenant', $summary['income_leaders'][0]['name']);
        $this->assertSame('Risk Tenant', $summary['expense_leaders'][0]['name']);

        $locations = collect($summary['geographic_summary'])->pluck('location')->all();
        $this->assertContains('Myanmar / Yangon', $locations);
        $this->assertContains('Myanmar / Mandalay', $locations);
        $this->assertNotContains('Thailand / Bangkok', $locations);

        $daySummary = app(PlatformDashboardService::class)->getSummary(new DashboardTimeFilter(
            DashboardTimeFilter::THIS_DAY,
            now(),
            now(),
        ));

        $this->assertSame(DashboardTimeFilter::THIS_DAY, $daySummary['filters']['timeFilter']);
        $this->assertSame(1050.0, $daySummary['financial']['periodIncome']);
        $this->assertSame(700.0, $daySummary['financial']['periodExpense']);
        $this->assertSame(350.0, $daySummary['financial']['periodNet']);
    }

    public function test_empty_platform_dashboard_has_no_data(): void
    {
        $owner = $this->platformUser('empty@example.com');
        $this->actingAs($owner, 'platformuser');

        $summary = app(PlatformDashboardService::class)->getSummary();

        $this->assertFalse($summary['has_data']);
        $this->assertSame(0, $summary['tenant_counts']['total']);
    }

    public function test_dashboard_time_filter_from_validated_preserves_this_day(): void
    {
        $filter = DashboardTimeFilter::fromValidated([
            'time_filter' => DashboardTimeFilter::THIS_DAY,
        ]);

        $this->assertSame(DashboardTimeFilter::THIS_DAY, $filter->timeFilter);
        $this->assertSame('2026-06-20', $filter->startDate->toDateString());
        $this->assertSame('2026-06-20', $filter->endDate->toDateString());
    }

    protected function platformUser(string $email): PlatformUser
    {
        return PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Dashboard Owner',
            'email' => $email,
            'password' => 'secret123',
            'status' => 'active',
        ]);
    }

    protected function tenant(PlatformUser $owner, string $code, string $name): Tenant
    {
        return Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => $name,
            'tenant_code' => $code,
            'subdomain' => $code,
            'status' => 'active',
        ]);
    }

    protected function license(Tenant $tenant, string $plan, string $status, array $extra = []): TenantLicense
    {
        return TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => strtoupper(substr($tenant->tenant_code, 0, 10)).str_pad((string) $tenant->id, 6, '0', STR_PAD_LEFT),
            'plan_type' => $plan,
            'status' => $status,
            'starts_at' => now()->subMonth(),
            'expires_at' => $status === 'expired' ? now()->subDay() : now()->addMonth(),
            'activated_at' => now()->subMonth(),
            ...$extra,
        ]);
    }

    protected function contact(Tenant $tenant, string $city, string $country): void
    {
        DB::table('tenant_contacts')->insert([
            'tenant_id' => $tenant->id,
            'tenant_code' => $tenant->tenant_code,
            'address' => 'Main road',
            'phone' => '09111111111',
            'city' => $city,
            'country' => $country,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function accounting(Tenant $tenant, string $type, float $amount, string $description, Carbon $createdAt): void
    {
        DB::table('tenant_accountings')->insert([
            'tenant_id' => $tenant->id,
            'description' => $description,
            'transaction_type' => $type,
            'amount' => $amount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function debt(Tenant $tenant, float $amount): void
    {
        DB::table('tenant_debts')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'DEBT'.$tenant->id,
            'amount' => $amount,
            'description' => 'Outstanding debt',
            'is_paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function activeSlipWithCollateral(Tenant $tenant, float $minimumRetailPrice): void
    {
        $customerId = DB::table('tenant_customers')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'CUS'.$tenant->id,
            'name' => 'Collateral Customer '.$tenant->id,
            'phone' => '09'.str_pad((string) $tenant->id, 9, '1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $slipId = DB::table('pawn_loan_contract_slips')->insertGetId([
            'tenant_id' => $tenant->id,
            'slip_no' => 'SLIP'.$tenant->id,
            'customer_id' => $customerId,
            'loan_amount' => 500,
            'interest_rate' => 5,
            'created_date' => now()->toDateString(),
            'expire_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pawn_collateral_items')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'COL'.$tenant->id,
            'loan_contract_id' => $slipId,
            'type' => 'Normal',
            'name' => 'Collateral '.$tenant->id,
            'description' => 'Dashboard collateral',
            'estimated_value' => $minimumRetailPrice,
            'item_status' => 'active',
            'quantity' => 1,
            'minimum_retail_price' => $minimumRetailPrice,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
