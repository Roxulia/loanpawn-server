<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\TenantCustomer;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\CustomerTrustScoreService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerTrustScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_customer_without_slips_scores_zero(): void
    {
        $tenant = $this->createTenant('empty-score-tenant');
        app(TenantContext::class)->set($tenant);
        $customer = $this->createCustomer($tenant, 'Empty Customer');

        $score = app(CustomerTrustScoreService::class)->recalculateForCustomer($customer->id);

        $this->assertSame(0, $score);
        $this->assertDatabaseHas('tenant_customers', [
            'id' => $customer->id,
            'trust_score' => 0,
        ]);
    }

    public function test_on_time_redeemed_customer_receives_high_score(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-25 10:00:00'));
        $tenant = $this->createTenant('high-score-tenant');
        app(TenantContext::class)->set($tenant);
        $customer = $this->createCustomer($tenant, 'High Score Customer');
        $slipId = $this->createSlip($tenant, $customer, [
            'slip_no' => 'SCORE-HIGH-001',
            'loan_amount' => 100000,
            'created_date' => '2025-06-25',
            'expire_date' => '2025-12-25',
            'status' => 'redeemed',
        ]);
        $this->createInterestPayment($tenant, $slipId, [
            'start_period' => '2025-06-25',
            'end_period' => '2025-07-24',
            'payment_date' => '2025-07-20',
            'is_paid' => true,
        ]);

        $score = app(CustomerTrustScoreService::class)->recalculateForCustomer($customer->id);

        $this->assertGreaterThanOrEqual(200, $score);
    }

    public function test_unpaid_debt_and_overdue_interest_reduce_score(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-25 10:00:00'));
        $tenant = $this->createTenant('low-score-tenant');
        app(TenantContext::class)->set($tenant);
        $customer = $this->createCustomer($tenant, 'Low Score Customer');
        $slipId = $this->createSlip($tenant, $customer, [
            'slip_no' => 'SCORE-LOW-001',
            'loan_amount' => 100000,
            'created_date' => '2025-06-25',
            'expire_date' => '2026-12-25',
            'status' => 'active',
        ]);
        $this->createInterestPayment($tenant, $slipId, [
            'start_period' => '2026-04-25',
            'end_period' => '2026-05-24',
            'payment_date' => null,
            'is_paid' => false,
        ]);
        DB::table('tenant_debts')->insert([
            'tenant_id' => $tenant->id,
            'code' => 'DEBT-LOW-001',
            'slip_id' => $slipId,
            'amount' => 50000,
            'description' => 'Unpaid interest',
            'tag' => 'InterestPayment',
            'is_paid' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $score = app(CustomerTrustScoreService::class)->recalculateForCustomer($customer->id);

        $this->assertLessThan(80, $score);
    }

    public function test_score_calculation_is_tenant_isolated(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-25 10:00:00'));
        $firstTenant = $this->createTenant('first-isolated-tenant');
        $secondTenant = $this->createTenant('second-isolated-tenant');
        $firstCustomer = $this->createCustomer($firstTenant, 'First Tenant Customer');
        $secondCustomer = $this->createCustomer($secondTenant, 'Second Tenant Customer');
        $slipId = $this->createSlip($secondTenant, $secondCustomer, [
            'slip_no' => 'SCORE-OTHER-001',
            'loan_amount' => 100000,
            'created_date' => '2025-06-25',
            'expire_date' => '2025-12-25',
            'status' => 'redeemed',
        ]);
        $this->createInterestPayment($secondTenant, $slipId, [
            'start_period' => '2025-06-25',
            'end_period' => '2025-07-24',
            'payment_date' => '2025-07-20',
            'is_paid' => true,
        ]);

        app(TenantContext::class)->set($firstTenant);
        $score = app(CustomerTrustScoreService::class)->recalculateForCustomer($firstCustomer->id);

        $this->assertSame(0, $score);
    }

    protected function createTenant(string $code): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner '.$code,
            'email' => $code.'@example.com',
            'phone' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Tenant '.$code,
            'tenant_code' => $code,
            'subdomain' => $code,
            'status' => 'active',
        ]);
    }

    protected function createCustomer(Tenant $tenant, string $name): TenantCustomer
    {
        return TenantCustomer::query()->withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenant->id,
            'code' => 'CUST'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'name' => $name,
            'phone' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'trust_score' => 0,
            'is_deleted' => false,
        ]);
    }

    protected function createSlip(Tenant $tenant, TenantCustomer $customer, array $overrides): int
    {
        return DB::table('pawn_loan_contract_slips')->insertGetId(array_merge([
            'tenant_id' => $tenant->id,
            'slip_no' => 'SCORE-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'loan_amount' => 100000,
            'interest_rate' => 10,
            'created_date' => '2026-01-01',
            'expire_date' => '2026-06-01',
            'last_interest_added_date' => '2026-01-01',
            'last_interest_paid_date' => null,
            'status' => 'active',
            'expiry_quota' => 6,
            'expiry_quota_type' => 'Month',
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    protected function createInterestPayment(Tenant $tenant, int $slipId, array $overrides): void
    {
        DB::table('pawn_interest_payments')->insert(array_merge([
            'tenant_id' => $tenant->id,
            'slip_id' => $slipId,
            'payment_amount' => 10000,
            'change_amount' => 0,
            'calculated_interest' => 10000,
            'payment_date' => null,
            'start_period' => '2026-01-01',
            'end_period' => '2026-01-31',
            'is_paid' => false,
            'is_deleted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
