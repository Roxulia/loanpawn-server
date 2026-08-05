<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\DataObjects\RequestObjects\TenantCustomerUpdate;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\TenantCustomerService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantCustomerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_customer_when_no_duplicate_exists(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['access_all']);

        $result = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Mg Mg',
            email: 'mgmg@example.com',
            phone: '0911111111',
            address: 'Yangon',
            trustScore: 3,
            note: 'Trusted',
        ));

        $this->assertTrue($result->created);
        $this->assertSame('Mg Mg', $result->customer->name);
        $this->assertSame($tenantUser->id, $result->customer->createdBy);

        $this->assertDatabaseHas('tenant_customers', [
            'tenant_id' => $tenant->id,
            'name' => 'Mg Mg',
            'email' => 'mgmg@example.com',
            'phone' => '0911111111',
            'is_deleted' => false,
            'created_by' => $tenantUser->id,
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $tenantUser->id,
            'action' => 'tenant_customer.created',
            'target_type' => 'App\\Models\\CoreModule\\TenantCustomer',
            'target_id' => $result->customer->id,
        ]);
    }

    public function test_it_returns_existing_customer_when_duplicate_phone_exists(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);

        $existing = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Original',
            email: 'original@example.com',
            phone: '0922222222',
        ));

        $result = app(TenantCustomerService::class)->createCustomer(new TenantCustomerCreate(
            name: 'Duplicate Attempt',
            email: 'different@example.com',
            phone: '0922222222',
        ));

        $this->assertFalse($result->created);
        $this->assertSame($existing->customer->id, $result->customer->id);
        $this->assertDatabaseCount('tenant_customers', 1);
    }

    public function test_it_updates_customer(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);

        $created = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Before',
            email: 'before@example.com',
            phone: '0933333333',
        ));

        $updated = app(TenantCustomerService::class)->update(new TenantCustomerUpdate(
            customerId: $created->customer->id,
            code: $created->customer->code,
            updateKey: $created->customer->updateKey,
            name: 'After',
            trustScore: 5,
            note: 'Updated',
        ));

        $this->assertSame('After', $updated->name);
        $this->assertSame(5, $updated->trustScore);

        $this->assertDatabaseHas('tenant_customers', [
            'id' => $created->customer->id,
            'name' => 'After',
            'trust_score' => 5,
            'note' => 'Updated',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'tenant_customer.updated',
            'target_type' => 'App\\Models\\CoreModule\\TenantCustomer',
            'target_id' => $created->customer->id,
        ]);
    }

    public function test_it_shows_customer_detail(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);

        $created = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Detail Customer',
            email: 'detail@example.com',
            phone: '0999999999',
        ));

        $detail = app(TenantCustomerService::class)->show($created->customer->id);

        $this->assertSame($created->customer->id, $detail->id);
        $this->assertSame('Detail Customer', $detail->name);
    }

    public function test_it_shows_customer_detail_with_related_unpaid_debts(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);

        $target = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Debt Detail Customer',
            phone: '0912345678',
        ));
        $other = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Other Debt Customer',
            phone: '0987654321',
        ));

        $slip = PawnLoanContractSlip::query()->create([
            'tenant_id' => $tenant->id,
            'slip_no' => 'SLIP-DEBT-001',
            'customer_id' => $target->customer->id,
            'loan_amount' => 100000,
            'interest_rate' => 5,
            'created_at' => now()->subDays(10),
            'expire_at' => now()->addMonth(),
            'status' => 'active',
        ]);

        DB::table('tenant_debts')->insert([
            [
                'tenant_id' => $tenant->id,
                'code' => 'DEBT-DIRECT',
                'customer_id' => $target->customer->id,
                'slip_id' => null,
                'amount' => 25000,
                'description' => 'Direct unpaid debt',
                'tag' => 'direct',
                'is_paid' => false,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinutes(2),
            ],
            [
                'tenant_id' => $tenant->id,
                'code' => 'DEBT-SLIP',
                'customer_id' => null,
                'slip_id' => $slip->id,
                'amount' => 15000,
                'description' => 'Slip unpaid debt',
                'tag' => 'interest',
                'is_paid' => false,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'tenant_id' => $tenant->id,
                'code' => 'DEBT-PAID',
                'customer_id' => $target->customer->id,
                'slip_id' => null,
                'amount' => 5000,
                'description' => 'Paid debt',
                'tag' => 'paid',
                'is_paid' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $tenant->id,
                'code' => 'DEBT-OTHER',
                'customer_id' => $other->customer->id,
                'slip_id' => null,
                'amount' => 9000,
                'description' => 'Other customer debt',
                'tag' => 'other',
                'is_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $detail = app(TenantCustomerService::class)->show($target->customer->id);
        $debtCodes = collect($detail->unpaidDebts)->pluck('code')->all();

        $this->assertCount(2, $detail->unpaidDebts);
        $this->assertContains('DEBT-DIRECT', $debtCodes);
        $this->assertContains('DEBT-SLIP', $debtCodes);
        $this->assertNotContains('DEBT-PAID', $debtCodes);
        $this->assertNotContains('DEBT-OTHER', $debtCodes);
        $this->assertArrayNotHasKey('slip_no', $detail->unpaidDebts[0]);
        $this->assertArrayHasKey('amount', $detail->unpaidDebts[0]);
        $this->assertArrayHasKey('tag', $detail->unpaidDebts[0]);
        $this->assertArrayHasKey('created_at', $detail->unpaidDebts[0]);
    }

    public function test_it_searches_customers_by_name_phone_email_or_address(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);

        app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Aye Aye',
            email: 'aye@example.com',
            phone: '0911111111',
            address: 'Yangon',
        ));

        app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Ko Ko',
            email: 'koko@example.com',
            phone: '0922222222',
            address: 'Mandalay',
        ));

        $result = app(TenantCustomerService::class)->list(15, 'mandalay');

        $this->assertSame(1, $result->total);
        $this->assertSame('Ko Ko', $result->items[0]->name);
    }

    public function test_it_lists_customer_summary_and_last_activity(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);

        $trusted = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Trusted Customer',
            email: 'trusted@example.com',
            phone: '0911111111',
            address: 'Yangon',
            trustScore: 255,
        ));

        app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Risk Customer',
            email: 'risk@example.com',
            phone: '0922222222',
            address: 'Mandalay',
            trustScore: 20,
        ));

        PawnLoanContractSlip::query()->create([
            'tenant_id' => $tenant->id,
            'slip_no' => 'SLIP-001',
            'customer_id' => $trusted->customer->id,
            'loan_amount' => 100000,
            'interest_rate' => 5,
            'created_at' => now()->subDays(10),
            'expire_at' => now()->subDay()->startOfDay(),
            'status' => 'active',
        ]);

        $result = app(TenantCustomerService::class)->list(15);
        $trustedRow = collect($result->items)->firstWhere('name', 'Trusted Customer');

        $this->assertSame(2, $result->summary->totalClients);
        $this->assertSame(53.9, $result->summary->averageTrustScore);
        $this->assertSame(1, $result->summary->activePawnLoans);
        $this->assertSame(2, $result->summary->riskFlagged);
        $this->assertSame(100, $trustedRow->displayTrustScore);
        $this->assertSame(1, $trustedRow->activeSlipCount);
        $this->assertSame('Yangon', $trustedRow->primaryLocation);
        $this->assertSame('PAYMENT DELINQUENT', $trustedRow->lastActivity->status);
        $this->assertSame('danger', $trustedRow->lastActivity->tone);
    }

    public function test_it_flag_deletes_customer_with_soft_delete(): void
    {
        $tenant = $this->createTenant();
        $this->actingTenantUser($tenant, ['access_all']);

        $created = app(TenantCustomerService::class)->createForCurrentTenant(new TenantCustomerCreate(
            name: 'Delete Me',
            phone: '0944444444',
        ));

        app(TenantCustomerService::class)->delete($created->customer->id);

        $this->assertSoftDeleted('tenant_customers', [
            'id' => $created->customer->id,
        ]);

        $this->assertDatabaseHas('tenant_customers', [
            'id' => $created->customer->id,
            'is_deleted' => true,
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'tenant_customer.deleted',
            'target_type' => 'App\\Models\\CoreModule\\TenantCustomer',
            'target_id' => $created->customer->id,
        ]);
    }

    protected function createTenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'phone' => '09999999999',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Demo Tenant',
            'tenant_code' => 'demo-tenant',
            'subdomain' => 'demo-subdomain',
            'status' => 'active',
        ]);
    }

    protected function actingTenantUser(Tenant $tenant, array $permissions): TenantUser
    {
        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Admin',
            'description' => 'Admin role',
            'is_default' => true,
            'permissions' => $permissions,
        ]);

        $tenantUser = TenantUser::query()->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'ADMIN001',
            'name' => 'Tenant Admin',
            'nrc' => '12/PaTaNa(N)000001',
            'email' => 'tenant-admin@example.com',
            'phone' => '0955555555',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($tenantUser);

        return $tenantUser;
    }
}
