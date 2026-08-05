<?php

namespace Tests\Feature\PawnModule;

use App\DataObjects\RequestObjects\LoanContractSlipCreate;
use App\DataObjects\RequestObjects\InterestPaymentAccept;
use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\DataObjects\ResponseObjects\InterestBreakDown;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\PawnModule\InterestFlowService;
use App\Services\PawnModule\LoanContractServices\ManagementService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InterestFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_calculates_due_interest_by_slip_number(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-28 09:30:00'));
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Interest Customer',
                phone: '09900000101',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Laptop',
                    estimatedValue: 200000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 100000,
            interestRate: 10,
            interestTypeId: $interestType->id,
            expiryQuota: 4,
            expiryQuotaType: 'Month',
        ));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 10:00:00'));
        $result = app(InterestFlowService::class)->calculateInterestBySlipNo($created->slipNo);

        $this->assertSame($created->slipNo, $result->slipNo);
        $this->assertSame('2026-04-04', $result->currentDate);
        $this->assertCount(2, $result->interestBreakdown);
        $this->assertSame(20000.0, $result->totalInterestAmount);
        $this->assertSame('2026-02-28', CarbonImmutable::parse($result->interestBreakdown[0]->startPeriodAt)->toDateString());
        $this->assertSame('2026-03-28', CarbonImmutable::parse($result->interestBreakdown[1]->startPeriodAt)->toDateString());
        $this->assertIsInt($result->interestBreakdown[0]->updateKey);
    }

    public function test_it_records_debt_updates_slip_dates_and_regenerates_interest_schedule(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-28 09:30:00'));
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Debt Customer',
                phone: '09900000102',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Camera',
                    estimatedValue: 250000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 100000,
            interestRate: 10,
            interestTypeId: $interestType->id,
            expiryQuota: 4,
            expiryQuotaType: 'Month',
        ));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 10:00:00'));
        $calculation = app(InterestFlowService::class)->calculateInterestBySlipNo($created->slipNo);
        $result = app(InterestFlowService::class)->payInterestBySlipNo(
            $created->slipNo,
            new InterestPaymentAccept(
                slipUpdateKey: $calculation->slipUpdateKey,
                paymentAmount: 15000,
                recordDebt: true,
                interestBreakdown: $this->toInterestPaymentRequestBreakdown($calculation->interestBreakdown),
            )
        );

        $this->assertSame('debt_created', $result['status']);
        $this->assertSame(5000.0, $result['debtAmount']);
        $this->assertSame(0.0, $result['changeAmount']);

        $this->assertDatabaseHas('tenant_debts', [
            'slip_id' => $created->id,
            'customer_id' => $created->customerId,
            'amount' => '5000.00',
            'tag' => 'InterestPayment',
        ]);

        $this->assertDatabaseHas('pawn_loan_contract_slips', [
            'id' => $created->id,
            'last_interest_paid_at' => '2026-04-04 10:00:00',
            'last_interest_added_at' => '2026-04-04 10:00:00',
            'expire_at' => '2026-08-04 00:00:00',
        ]);

        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-02-28 00:00:00',
            'payment_amount' => '10000.00',
            'is_paid' => true,
        ]);
        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-03-28 00:00:00',
            'payment_amount' => '5000.00',
            'is_paid' => true,
        ]);
        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-05-04 00:00:00',
            'end_period_at' => '2026-06-03 00:00:00',
            'is_paid' => false,
        ]);

        $this->assertDatabaseCount('pawn_interest_payments', 5);
        $this->assertDatabaseHas('tenant_accountings', [
            'description' => 'Interest Payment Transaction',
            'transaction_type' => 'incoming',
            'amount' => '10000.00',
            'created_by' => $tenantUser->id,
        ]);
        $this->assertDatabaseHas('tenant_accountings', [
            'description' => 'Interest Payment Transaction',
            'transaction_type' => 'incoming',
            'amount' => '5000.00',
            'created_by' => $tenantUser->id,
        ]);
        $this->assertDatabaseHas('tenant_accountings', [
            'description' => 'Remaining interest from payment ID: 2',
            'transaction_type' => 'internal',
            'amount' => '5000.00',
            'created_by' => $tenantUser->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantDebt',
        ]);

        $trustScore = (int) DB::table('tenant_customers')
            ->where('id', $created->customerId)
            ->value('trust_score');
        $this->assertGreaterThan(50, $trustScore);
        $this->assertLessThan(100, $trustScore);
    }

    public function test_it_records_gross_incoming_and_outgoing_change_when_payment_exceeds_due_interest(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-28 09:30:00'));
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Change Customer',
                phone: '09900000103',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Phone',
                    estimatedValue: 150000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 100000,
            interestRate: 10,
            interestTypeId: $interestType->id,
            expiryQuota: 4,
            expiryQuotaType: 'Month',
        ));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-04 10:00:00'));
        $calculation = app(InterestFlowService::class)->calculateInterestBySlipNo($created->slipNo);
        $result = app(InterestFlowService::class)->payInterestBySlipNo(
            $created->slipNo,
            new InterestPaymentAccept(
                slipUpdateKey: $calculation->slipUpdateKey,
                paymentAmount: 25000,
                recordDebt: false,
                interestBreakdown: $this->toInterestPaymentRequestBreakdown($calculation->interestBreakdown),
            )
        );

        $this->assertSame('change_made', $result['status']);
        $this->assertSame(0.0, $result['debtAmount']);
        $this->assertSame(5000.0, $result['changeAmount']);

        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-03-28 00:00:00',
            'payment_amount' => '15000.00',
            'change_amount' => '5000.00',
            'is_paid' => true,
        ]);

        $this->assertDatabaseHas('tenant_accountings', [
            'description' => 'Interest Payment Change Transaction',
            'transaction_type' => 'outgoing',
            'amount' => '5000.00',
            'reference_type' => 'App\\Models\\PawnModule\\PawnInterestPayment',
        ]);

        $incomingTotal = (float) DB::table('tenant_accountings')
            ->where('description', 'Interest Payment Transaction')
            ->where('transaction_type', 'incoming')
            ->sum('amount');

        $this->assertSame(25000.0, $incomingTotal);
    }

    public function test_daily_interest_with_day_quota_extends_expiry_by_paid_interest_row_count(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'daily',
            'name' => 'Daily',
            'duration_in_days' => 1,
            'is_default' => true,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 09:30:00'));
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Daily Interest Customer',
                phone: '09900000104',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Watch',
                    estimatedValue: 150000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 100000,
            interestRate: 1,
            interestTypeId: $interestType->id,
            expiryQuota: 5,
            expiryQuotaType: 'Day',
        ));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-03 10:00:00'));
        $calculation = app(InterestFlowService::class)->calculateInterestBySlipNo($created->slipNo);

        $this->assertCount(3, $calculation->interestBreakdown);

        app(InterestFlowService::class)->payInterestBySlipNo(
            $created->slipNo,
            new InterestPaymentAccept(
                slipUpdateKey: $calculation->slipUpdateKey,
                paymentAmount: 3000,
                recordDebt: false,
                interestBreakdown: $this->toInterestPaymentRequestBreakdown($calculation->interestBreakdown),
            )
        );

        $this->assertDatabaseHas('pawn_loan_contract_slips', [
            'id' => $created->id,
            'expire_at' => '2026-07-09 00:00:00',
        ]);
    }

    public function test_migration_corrects_expiry_when_day_quota_is_shorter_than_interest_duration(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 09:30:00'));
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Short Quota Customer',
                phone: '09900000105',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Bracelet',
                    estimatedValue: 150000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 100000,
            interestRate: 1,
            interestTypeId: $interestType->id,
            expiryQuota: 5,
            expiryQuotaType: 'Day',
        ));

        $migration = require database_path(
            'migrations/2026_07_30_000001_correct_short_day_quota_slip_expiry_dates.php'
        );
        $migration->up();

        $this->assertDatabaseHas('pawn_loan_contract_slips', [
            'id' => $created->id,
            'expire_at' => '2026-07-11 00:00:00',
            'update_key' => 1,
        ]);
    }

    protected function createTenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Slip Owner',
            'email' => 'slip-owner@example.com',
            'phone' => '09111113333',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Slip Tenant',
            'tenant_code' => 'slip-tenant',
            'subdomain' => 'slip-subdomain',
            'status' => 'active',
        ]);
    }

    protected function actingTenantUser(Tenant $tenant, array $permissions): TenantUser
    {
        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Slip Role',
            'description' => 'Slip role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);

        $tenantUser = TenantUser::query()->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'SLIP001',
            'name' => 'Slip User',
            'nrc' => '12/PaTaNa(N)000333',
            'email' => 'slip-user@example.com',
            'phone' => '0955555333',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($tenantUser);

        return $tenantUser;
    }

    protected function toInterestPaymentRequestBreakdown(array $interestBreakdown): array
    {
        return array_map(
            fn ($breakdown): InterestBreakDown => InterestBreakDown::fromValues(
                id: $breakdown->id,
                updateKey: $breakdown->updateKey,
                interestAmount: $breakdown->interestAmount,
                startPeriodAt: $breakdown->startPeriodAt,
                endPeriodAt: $breakdown->endPeriodAt,
            ),
            $interestBreakdown
        );
    }
}
