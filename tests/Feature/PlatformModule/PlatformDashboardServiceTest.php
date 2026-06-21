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
        $stableTenant = $this->tenant($owner, 'stable-tenant', 'Stable Tenant');
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
        $this->license($stableTenant, 'premium', 'active');
        $this->license($otherTenant, 'premium', 'active', [
            'current_month_slip_count' => 999,
            'current_staff_count' => 99,
        ]);

        $this->contact($healthyTenant, 'Yangon', 'Myanmar');
        $this->contact($stableTenant, 'Naypyidaw', 'Myanmar');
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
        $this->expiringSlipRisk($healthyTenant, 'HEALTHY-EXPIRING', 1000, 100, 50, 2000, now()->addDays(3));
        $this->expiringSlipRisk($riskTenant, 'RISK-EXPIRING', 500, 100, 100, 500, now()->addDays(2));
        $this->expiringSlipRisk($stableTenant, 'STABLE-FUTURE', 9999, 999, 999, 1, now()->addDays(12));
        $this->expiringSlipRisk($otherTenant, 'OTHER-EXPIRING', 9999, 999, 999, 1, now()->addDays(2));

        $this->actingAs($owner, 'platformuser');

        $summary = app(PlatformDashboardService::class)->getSummary();

        $this->assertTrue($summary['has_data']);
        $this->assertSame(DashboardTimeFilter::THIS_MONTH, $summary['filters']['timeFilter']);
        $this->assertSame(3, $summary['tenant_counts']['total']);
        $this->assertSame(1, $summary['tenant_counts']['expired']);
        $this->assertSame(1, $summary['plan_breakdown']['basic']);
        $this->assertSame(1, $summary['plan_breakdown']['trial']);
        $this->assertSame(1, $summary['plan_breakdown']['premium']);
        $this->assertSame(70, $summary['tenant_counts']['slipCurrentCount']);
        $this->assertSame(330, $summary['tenant_counts']['slipMaxCount']);
        $this->assertSame(21.21, $summary['tenant_counts']['slipUsagePercent']);
        $this->assertSame(4, $summary['tenant_counts']['staffCurrentCount']);
        $this->assertSame(7, $summary['tenant_counts']['staffMaxCount']);
        $this->assertSame(57.14, $summary['tenant_counts']['staffUsagePercent']);
        $this->assertSame(57.14, $summary['tenant_counts']['resourceUsagePercent']);

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
        $this->assertSame(12501.0, $summary['financial']['activeCollateralMinimumRetailPrice']);
        $this->assertSame(12751.0, $summary['financial']['unrealizedNetworth']);
        $this->assertCount(3, $summary['executive_overview']['kpis']);
        $this->assertSame('total_tenants', $summary['executive_overview']['kpis'][0]['labelKey']);
        $this->assertSame(3, $summary['executive_overview']['kpis'][0]['value']);
        $this->assertSame('portfolio_net_worth', $summary['executive_overview']['kpis'][1]['labelKey']);
        $this->assertNotEmpty($summary['executive_overview']['benchmarkRows']);
        $this->assertArrayHasKey('overviewBenchmark', $summary['charts']);
        $this->assertContains('Healthy Tenant', $summary['charts']['overviewBenchmark']['labels']);
        $this->assertSame(['Monthly slips'], $summary['charts']['slipPackageUsage']['labels']);
        $this->assertSame([70], $summary['charts']['slipPackageUsage']['current']);
        $this->assertSame([330], $summary['charts']['slipPackageUsage']['max']);
        $this->assertSame(['Staff'], $summary['charts']['staffPackageUsage']['labels']);
        $this->assertSame([4], $summary['charts']['staffPackageUsage']['current']);
        $this->assertSame([7], $summary['charts']['staffPackageUsage']['max']);
        $this->assertCount(3, $summary['financial_performance']['kpis']);
        $this->assertSame('total_portfolio_revenue', $summary['financial_performance']['kpis'][0]['labelKey']);
        $this->assertSame(1650.0, $summary['financial_performance']['kpis'][0]['value']);

        $financialRows = collect($summary['financial_performance']['tenantRows'])->keyBy('name');
        $this->assertTrue($financialRows->has('Healthy Tenant'));
        $this->assertTrue($financialRows->has('Stable Tenant'));
        $this->assertTrue($financialRows->has('Risk Tenant'));
        $this->assertFalse($financialRows->has('Other Tenant'));
        $this->assertSame('high_yield', $financialRows['Healthy Tenant']['statusKey']);
        $this->assertSame('stable', $financialRows['Stable Tenant']['statusKey']);
        $this->assertSame('at_risk', $financialRows['Risk Tenant']['statusKey']);

        $this->assertSame(70, $summary['package_usage']['currentMonthSlipCount']);
        $this->assertSame(4, $summary['package_usage']['currentStaffCount']);
        $this->assertCount(3, $summary['financial_performance']['usageItems']);
        $this->assertSame('configured_tenants', $summary['financial_performance']['usageItems'][2]['labelKey']);
        $this->assertSame(100.0, $summary['financial_performance']['usageItems'][2]['percent']);
        $this->assertSame('Risk Tenant', $summary['risk_tenants'][0]['name']);
        $this->assertSame('critical', $summary['risk_tenants'][0]['riskLabel']);
        $this->assertCount(2, $summary['expiring_contract_risks']);
        $this->assertSame('Risk Tenant', $summary['expiring_contract_risks'][0]['name']);
        $this->assertSame(1, $summary['expiring_contract_risks'][0]['contractCount']);
        $this->assertSame(700.0, $summary['expiring_contract_risks'][0]['collectibleTotal']);
        $this->assertSame(500.0, $summary['expiring_contract_risks'][0]['minimumRetailTotal']);
        $this->assertSame(140.0, $summary['expiring_contract_risks'][0]['riskValue']);
        $this->assertSame('Healthy Tenant', $summary['expiring_contract_risks'][1]['name']);
        $this->assertSame(1150.0, $summary['expiring_contract_risks'][1]['collectibleTotal']);
        $this->assertSame(2000.0, $summary['expiring_contract_risks'][1]['minimumRetailTotal']);
        $this->assertSame(57.5, $summary['expiring_contract_risks'][1]['riskValue']);
        $this->assertSame('Risk Tenant', $summary['executive_overview']['priorityEvents'][0]['tenant']);
        $this->assertSame('risk_critical', $summary['executive_overview']['priorityEvents'][0]['statusKey']);
        $this->assertSame('Healthy Tenant', $summary['income_leaders'][0]['name']);
        $this->assertSame('Risk Tenant', $summary['expense_leaders'][0]['name']);

        $locations = collect($summary['geographic_summary'])->pluck('location')->all();
        $this->assertContains('Myanmar / Yangon', $locations);
        $this->assertContains('Myanmar / Mandalay', $locations);
        $this->assertContains('Myanmar / Naypyidaw', $locations);
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
        $this->assertSame(0, $summary['tenant_counts']['slipCurrentCount']);
        $this->assertNull($summary['tenant_counts']['slipMaxCount']);
        $this->assertSame(0.0, $summary['tenant_counts']['slipUsagePercent']);
        $this->assertSame(0, $summary['tenant_counts']['staffCurrentCount']);
        $this->assertNull($summary['tenant_counts']['staffMaxCount']);
        $this->assertSame(0.0, $summary['tenant_counts']['staffUsagePercent']);
        $this->assertSame(0.0, $summary['tenant_counts']['resourceUsagePercent']);
        $this->assertCount(3, $summary['executive_overview']['kpis']);
        $this->assertSame([], $summary['executive_overview']['benchmarkRows']);
        $this->assertSame([], $summary['executive_overview']['priorityEvents']);
        $this->assertCount(3, $summary['financial_performance']['kpis']);
        $this->assertSame([], $summary['financial_performance']['tenantRows']);
        $this->assertCount(3, $summary['financial_performance']['usageItems']);
        $this->assertCount(3, $summary['financial_performance']['insights']);
        $this->assertSame([], $summary['expiring_contract_risks']);
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

    protected function expiringSlipRisk(
        Tenant $tenant,
        string $slipNo,
        float $loanAmount,
        float $interestAmount,
        float $debtAmount,
        float $minimumRetailPrice,
        Carbon $expireDate,
    ): void {
        $customerId = DB::table('tenant_customers')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'CUS'.$slipNo,
            'name' => 'Expiring Customer '.$slipNo,
            'phone' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $slipId = DB::table('pawn_loan_contract_slips')->insertGetId([
            'tenant_id' => $tenant->id,
            'slip_no' => $slipNo,
            'customer_id' => $customerId,
            'loan_amount' => $loanAmount,
            'interest_rate' => 5,
            'created_date' => now()->subMonth()->toDateString(),
            'expire_date' => $expireDate->toDateString(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pawn_interest_payments')->insert([
            'tenant_id' => $tenant->id,
            'slip_id' => $slipId,
            'payment_amount' => 0,
            'change_amount' => 0,
            'calculated_interest' => $interestAmount,
            'start_period' => now()->toDateString(),
            'end_period' => $expireDate->toDateString(),
            'is_paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_debts')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'DEBT'.$slipNo,
            'slip_id' => $slipId,
            'amount' => $debtAmount,
            'description' => 'Expiring slip debt',
            'is_paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pawn_collateral_items')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'COL'.$slipNo,
            'loan_contract_id' => $slipId,
            'type' => 'Normal',
            'name' => 'Expiring Collateral '.$slipNo,
            'description' => 'Expiring dashboard collateral',
            'estimated_value' => $minimumRetailPrice,
            'item_status' => 'active',
            'quantity' => 1,
            'minimum_retail_price' => $minimumRetailPrice,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
