<?php

namespace Tests\Feature\PawnModule;

use App\DataObjects\RequestObjects\LoanContractSlipCreate;
use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\PawnModule\LoanContractServices\LookUpService;
use App\Services\PawnModule\LoanContractServices\ManagementService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoanContractSlipServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_creates_lists_and_finds_loan_contract_slip_by_slip_no(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Slip Customer',
                phone: '09900000001',
                address: 'Yangon',
                note: 'Known customer',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Laptop',
                    description: 'Pawned laptop',
                    brandName: 'Lenovo',
                    estimatedValue: 900000,
                    itemStatus: 'pawned',
                    quantity: 1,
                    minimumRetailPrice: 1000000
                ),
            ],
            loanAmount: 500000,
            interestRate: 5,
            interestTypeId: $interestType->id,
            notes: 'Loan note',
            expiryQuota: 3,
            expiryQuotaType: 'Month',
        ));

        $this->assertSame('LS202604slip-tenant001', $created->slipNo);
        $this->assertSame('2026-04-21', CarbonImmutable::parse($created->createdAt)->toDateString());
        $this->assertSame('2026-07-21', CarbonImmutable::parse($created->expireAt)->toDateString());
        $this->assertSame('active', $created->status);
        $this->assertSame('Loan note', $created->notes);
        $this->assertSame($tenantUser->id, $created->createdBy);
        $this->assertCount(1, $created->items);

        $found = app(LookUpService::class)->findBySlipNo($created->slipNo);
        $this->assertSame($created->id, $found->id);
        $this->assertSame('Slip Customer', $found->customer?->name);

        $list = app(LookUpService::class)->list();
        $this->assertCount(1, $list->items);

        $this->assertDatabaseHas('pawn_loan_contract_slips', [
            'tenant_id' => $tenant->id,
            'slip_no' => $created->slipNo,
            'customer_id' => $created->customerId,
            'loan_amount' => 500000,
            'interest_rate' => 5,
            'interest_type_id' => $interestType->id,
            'expire_at' => '2026-07-21 00:00:00',
            'expiry_quota' => 3,
            'expiry_quota_type' => 'Month',
        ]);

        $this->assertDatabaseHas('pawn_collateral_items', [
            'tenant_id' => $tenant->id,
            'loan_contract_id' => $created->id,
            'type' => 'Normal',
            'name' => 'Laptop',
            'brand_name' => 'Lenovo',
            'estimated_value' => '900000.00',
            'item_status' => 'pawned',
            'quantity' => 1,
            'minimum_retail_price' => '1000000.00',
            'is_deleted' => false,
        ]);

        $this->assertDatabaseHas('table_ids', [
            'tenant_id' => $tenant->id,
            'table_name' => 'pawn_loan_contract_slips',
            'current_year' => 2026,
            'current_month' => 4,
            'current_id' => 1,
        ]);

        $this->assertDatabaseCount('pawn_interest_payments', 3);
        $this->assertDatabaseHas('pawn_interest_payments', [
            'tenant_id' => $tenant->id,
            'slip_id' => $created->id,
            'payment_amount' => 0,
            'calculated_interest' => 25000,
            'start_period_at' => '2026-04-21 00:00:00',
            'end_period_at' => '2026-05-20 00:00:00',
            'is_paid' => false,
        ]);

        $this->assertDatabaseHas('tenant_accountings', [
            'tenant_id' => $tenant->id,
            'description' => 'Loan Contract Transaction',
            'transaction_type' => 'outgoing',
            'amount' => '500000.00',
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\PawnModule\\PawnLoanContractSlip',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'loan_contract_slip.created',
            'target_type' => 'App\\Models\\PawnModule\\PawnLoanContractSlip',
            'target_id' => $created->id,
        ]);
    }

    public function test_it_reuses_existing_customer_by_customer_service_duplicate_rule(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'weekly',
            'name' => 'Weekly',
            'duration_in_days' => 7,
            'is_default' => true,
        ]);
        $existingCustomer = TenantCustomer::query()->create([
            'code' => 'TC'.strtoupper(uniqid()),
            'tenant_id' => $tenant->id,
            'name' => 'Existing Customer',
            'phone' => '09900000002',
            'trust_score' => 0,
            'is_deleted' => false,
        ]);
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Different Name',
                phone: '09900000002',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Phone',
                    estimatedValue: 200000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 100000,
            interestRate: 3,
            interestTypeId: $interestType->id,
            expiryQuota: 2,
            expiryQuotaType: 'Week',
        ));

        $this->assertSame($existingCustomer->id, $created->customerId);
        $this->assertDatabaseCount('tenant_customers', 1);
        $this->assertSame('2026-05-05', CarbonImmutable::parse($created->expireAt)->toDateString());
    }

    public function test_it_deletes_slip_accounting_and_marks_slip_items_deleted(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 09:30:00'));

        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'daily',
            'name' => 'Daily',
            'duration_in_days' => 1,
            'is_default' => true,
        ]);
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Delete Slip Customer',
                phone: '09900000003',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Watch',
                    estimatedValue: 250000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 150000,
            interestRate: 4,
            interestTypeId: $interestType->id,
            expiryQuota: 10,
            expiryQuotaType: 'Day',
        ));

        app(ManagementService::class)->delete($created->id);

        $this->assertSoftDeleted('pawn_loan_contract_slips', [
            'id' => $created->id,
        ]);

        $this->assertDatabaseHas('pawn_collateral_items', [
            'loan_contract_id' => $created->id,
            'is_deleted' => true,
        ]);

        $this->assertDatabaseMissing('tenant_accountings', [
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\PawnModule\\PawnLoanContractSlip',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'loan_contract_slip.deleted',
            'target_type' => 'App\\Models\\PawnModule\\PawnLoanContractSlip',
            'target_id' => $created->id,
        ]);

        $this->assertSame(0, (int) DB::table('tenant_customers')
            ->where('id', $created->customerId)
            ->value('trust_score'));
    }

    public function test_it_creates_monthly_interest_rows_using_the_expected_boundaries(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-28 09:30:00'));

        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'monthly',
            'name' => 'Monthly',
            'duration_in_days' => 30,
            'is_default' => true,
        ]);
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Monthly Customer',
                phone: '09900000011',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Camera',
                    estimatedValue: 350000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 200000,
            interestRate: 10,
            interestTypeId: $interestType->id,
            expiryQuota: 4,
            expiryQuotaType: 'Month',
        ));

        $this->assertSame('2026-06-28', CarbonImmutable::parse($created->expireAt)->toDateString());
        $this->assertDatabaseCount('pawn_interest_payments', 4);
        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-02-28 00:00:00',
            'end_period_at' => '2026-03-27 00:00:00',
        ]);
    }

    public function test_it_creates_weekly_interest_rows_with_an_extra_row_for_remaining_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-02-28 09:30:00'));

        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);
        $interestType = InterestType::query()->create([
            'tenant_id' => null,
            'code' => 'weekly',
            'name' => 'Weekly',
            'duration_in_days' => 7,
            'is_default' => true,
        ]);
        $created = app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: 'Weekly Customer',
                phone: '09900000012',
            ),
            collateralItems: [
                new PawnCollateralItemCreate(
                    type: 'Normal',
                    name: 'Speaker',
                    estimatedValue: 180000,
                    itemStatus: 'pawned'
                ),
            ],
            loanAmount: 120000,
            interestRate: 8,
            interestTypeId: $interestType->id,
            expiryQuota: 4,
            expiryQuotaType: 'Month',
        ));

        $this->assertSame('2026-06-28', CarbonImmutable::parse($created->expireAt)->toDateString());
        $this->assertDatabaseCount('pawn_interest_payments', 18);
        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-02-28 00:00:00',
            'end_period_at' => '2026-03-06 00:00:00',
        ]);
        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-03-07 00:00:00',
            'end_period_at' => '2026-03-13 00:00:00',
        ]);
        $this->assertDatabaseHas('pawn_interest_payments', [
            'slip_id' => $created->id,
            'start_period_at' => '2026-06-27 00:00:00',
            'end_period_at' => '2026-06-28 00:00:00',
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
}
