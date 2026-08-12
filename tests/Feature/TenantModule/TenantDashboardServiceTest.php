<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\DashboardTimeFilter;
use App\Models\CoreModule\ItemCategoryType;
use App\Models\CoreModule\MaterialType;
use App\Models\CoreModule\TenantCapital;
use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\TenantAccountingTransactions;
use App\Services\TenantModule\TenantDashboardService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenantDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dashboard_summary_returns_financial_risk_and_collateral_situation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['dashboard']);

        $gold = MaterialType::query()->create([
            'tenant_id' => null,
            'code' => 'gold',
            'name' => 'Gold',
            'is_default' => true,
        ]);
        $watchCategory = ItemCategoryType::query()->create([
            'tenant_id' => null,
            'code' => 'watches',
            'name' => 'Watches',
            'is_default' => true,
        ]);
        $overdueCustomer = $this->createCustomer($tenant, 'Overdue Customer', 80);
        $dueCustomer = $this->createCustomer($tenant, 'Due Customer', 180);
        $overdueSlip = $this->createSlip($tenant, $overdueCustomer, 'LS-OVERDUE', 1000000, '2026-06-10');
        $dueSlip = $this->createSlip($tenant, $dueCustomer, 'LS-DUE', 200000, '2026-06-18');

        $capital = TenantCapital::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'CAP-DASH',
            'description' => 'Capital insertion',
            'amount' => 5000000,
            'created_at' => '2026-06-12 08:00:00',
        ]);
        TenantAccountingTransactions::query()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Capital insertion',
            'transaction_direction' => 'incoming',
            'accounting_category' => 'equity',
            'amount' => 5000000,
            'reference_id' => $capital->id,
            'reference_type' => TenantCapital::class,
            'business_date' => '2026-06-12',
            'occurred_at' => '2026-06-12 08:00:00',
            'created_at' => '2026-06-12 08:00:00',
        ]);
        TenantAccountingTransactions::query()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Loan slip payment',
            'transaction_direction' => 'outgoing',
            'accounting_category' => 'asset',
            'amount' => 1200000,
            'reference_id' => $overdueSlip->id,
            'reference_type' => PawnLoanContractSlip::class,
            'business_date' => '2026-06-13',
            'occurred_at' => '2026-06-13 08:00:00',
            'created_at' => '2026-06-13 08:00:00',
        ]);
        TenantAccountingTransactions::query()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Previous income',
            'transaction_direction' => 'incoming',
            'accounting_category' => 'revenue',
            'amount' => 3000000,
            'business_date' => '2026-05-30',
            'occurred_at' => '2026-05-30 08:00:00',
            'created_at' => '2026-05-30 08:00:00',
        ]);
        $expense = TenantExpense::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'EXP-DASH',
            'amount' => 1000000,
            'description' => 'Rent',
            'created_at' => '2026-06-13 08:00:00',
        ]);
        TenantAccountingTransactions::query()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Rent',
            'transaction_direction' => 'outgoing',
            'accounting_category' => 'expense',
            'amount' => 1000000,
            'reference_id' => $expense->id,
            'reference_type' => TenantExpense::class,
            'business_date' => '2026-06-13',
            'occurred_at' => '2026-06-13 08:00:00',
            'created_at' => '2026-06-13 08:00:00',
        ]);
        $debt = TenantDebt::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'DEBT-DASH',
            'slip_id' => $overdueSlip->id,
            'amount' => 50000,
            'description' => 'Unpaid fee',
            'is_paid' => false,
        ]);
        $debt->created_at = Carbon::parse('2026-06-01 09:00:00');
        $debt->save();
        TenantAccountingTransactions::query()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Debt payment',
            'transaction_direction' => 'incoming',
            'accounting_category' => 'liability',
            'amount' => 50000,
            'reference_id' => $debt->id,
            'reference_type' => TenantDebt::class,
            'business_date' => '2026-06-12',
            'occurred_at' => '2026-06-12 08:00:00',
            'created_at' => '2026-06-12 08:00:00',
        ]);
        $interestPayment = PawnInterestPayment::query()->create([
            'tenant_id' => $tenant->id,
            'slip_id' => $overdueSlip->id,
            'payment_amount' => 300000,
            'calculated_interest' => 300000,
            'payment_at' => '2026-06-12 00:00:00',
            'is_paid' => true,
        ]);
        TenantAccountingTransactions::query()->create([
            'tenant_id' => $tenant->id,
            'description' => 'Interest payment',
            'transaction_direction' => 'incoming',
            'accounting_category' => 'revenue',
            'amount' => 300000,
            'reference_id' => $interestPayment->id,
            'reference_type' => PawnInterestPayment::class,
            'business_date' => '2026-06-12',
            'occurred_at' => '2026-06-12 08:00:00',
            'created_at' => '2026-06-12 08:00:00',
        ]);
        PawnRedemption::query()->create([
            'tenant_id' => $tenant->id,
            'slip_number' => 'LS-RETURNED',
            'slip_id' => $dueSlip->id,
            'gross_amount' => 900000,
            'net_amount' => 800000,
            'interest_amount' => 100000,
            'received_amount' => 900000,
            'change_amount' => 0,
            'redemption_at' => '2026-06-12 00:00:00',
        ]);
        PawnCollateralItem::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'COL-GOLD',
            'loan_contract_id' => $overdueSlip->id,
            'type' => 'jewellery',
            'name' => 'Gold Bracelet',
            'estimated_value' => 2000000,
            'minimum_retail_price' => 2000000,
            'material_type_id' => $gold->id,
            'kyat' => 1,
            'pal' => 0,
            'yway' => 0,
            'item_status' => 'active',
        ]);
        PawnCollateralItem::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'COL-NORMAL',
            'loan_contract_id' => $dueSlip->id,
            'type' => 'normal',
            'name' => 'Phone',
            'estimated_value' => 500000,
            'minimum_retail_price' => 500000,
            'item_category_type_id' => $watchCategory->id,
            'item_status' => 'active',
        ]);
        PawnCollateralItem::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'COL-REDEEMED',
            'loan_contract_id' => $dueSlip->id,
            'type' => 'jewellery',
            'name' => 'Redeemed Ring',
            'estimated_value' => 9000000,
            'minimum_retail_price' => 9000000,
            'material_type_id' => $gold->id,
            'kyat' => 2,
            'pal' => 0,
            'yway' => 0,
            'item_status' => 'redeemed',
        ]);

        $summary = app(TenantDashboardService::class)
            ->summary(new DashboardTimeFilter(
                DashboardTimeFilter::CUSTOM,
                Carbon::parse('2026-06-01'),
                Carbon::parse('2026-06-15'),
            ))
            ->toArray();

        $this->assertSame(6150000.0, $summary['financial']['cashAvailable']);
        $this->assertSame(1200000.0, $summary['financial']['activeLoanAmount']);
        $this->assertSame(2, $summary['financial']['activeLoanCount']);
        $this->assertSame(300000.0, $summary['financial']['interestCollected']);
        $this->assertSame(300000.0, $summary['financial']['totalIncome']);
        $this->assertSame(1000000.0, $summary['financial']['totalExpenses']);
        $this->assertSame(-1850000.0, $summary['financial']['netProfit']);
        $this->assertSame(1250000.0, $summary['financial']['chart'][0]['loanAmount']);
        $this->assertSame(900000.0, $summary['financial']['chart'][11]['returnedAmount']);
        $this->assertSame(300000.0, $summary['financial']['chart'][11]['interest']);

        $this->assertSame(0, $summary['risk']['dueToday']);
        $this->assertSame(1, $summary['risk']['dueThisWeek']);
        $this->assertSame(1, $summary['risk']['overdueLoans']);
        $this->assertSame(1000000.0, $summary['risk']['overdueAmount']);
        $this->assertSame(1, $summary['risk']['highRiskCustomers']);
        $this->assertSame('High', $summary['risk']['loansRequiringAttention'][0]['riskLevel']);
        $this->assertSame('Medium', $summary['risk']['loansRequiringAttention'][1]['riskLevel']);

        $this->assertSame(2500000.0, $summary['collateral']['totalCollateralValue']);
        $this->assertSame(2000000.0, $summary['collateral']['goldJewelryValue']);
        $this->assertCount(2, $summary['collateral']['items']);
        $this->assertSame('Gold', $summary['collateral']['categoryBreakdown'][0]['category']);
        $this->assertSame('Watches', $summary['collateral']['categoryBreakdown'][1]['category']);
        $this->assertSame('Expired', $summary['collateral']['itemsNeedingReview'][0]['status']);
    }

    protected function createTenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Dashboard Owner',
            'email' => 'dashboard-owner@example.com',
            'phone' => '09111114444',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Dashboard Tenant',
            'tenant_code' => 'dashboard-tenant',
            'subdomain' => 'dashboard-subdomain',
            'status' => 'active',
        ]);
    }

    protected function actingTenantUser(Tenant $tenant, array $permissions): TenantUser
    {
        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Dashboard Role',
            'description' => 'Dashboard role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);

        $tenantUser = TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'DASH001',
            'name' => 'Dashboard User',
            'nrc' => '12/PaTaNa(N)000444',
            'email' => 'dashboard-user@example.com',
            'phone' => '0955555444',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($tenantUser);

        return $tenantUser;
    }

    protected function createCustomer(Tenant $tenant, string $name, int $trustScore): TenantCustomer
    {
        return TenantCustomer::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'TC'.strtoupper(uniqid()),
            'name' => $name,
            'phone' => '099'.random_int(1000000, 9999999),
            'trust_score' => $trustScore,
            'is_deleted' => false,
        ]);
    }

    protected function createSlip(Tenant $tenant, TenantCustomer $customer, string $slipNo, float $amount, string $expireDate): PawnLoanContractSlip
    {
        return PawnLoanContractSlip::query()->create([
            'tenant_id' => $tenant->id,
            'slip_no' => $slipNo,
            'customer_id' => $customer->id,
            'loan_amount' => $amount,
            'interest_rate' => 5,
            'created_at' => '2026-06-01 00:00:00',
            'expire_at' => CarbonImmutable::parse($expireDate)->startOfDay(),
            'status' => 'active',
            'expiry_quota' => 1,
            'expiry_quota_type' => 'Month',
        ]);
    }
}
