<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\TenantDebtCreate;
use App\DataObjects\RequestObjects\TenantDebtUpdate;
use App\DataObjects\RequestObjects\TenantExpenseCreate;
use App\DataObjects\RequestObjects\TenantExpenseUpdate;
use App\DataObjects\RequestObjects\TenantCapitalCreate;
use App\DataObjects\RequestObjects\TenantCapitalUpdate;
use App\DataObjects\RequestObjects\TenantAccountingCreate;
use App\Models\CoreModule\ExpenseType;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\TenantDebtService;
use App\Services\TenantModule\TenantAccountingService;
use App\Services\TenantModule\TenantCapitalService;
use App\Services\TenantModule\TenantDashboardService;
use App\Services\TenantModule\TenantExpenseService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenantFinanceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_updates_and_deletes_expense_with_linked_outgoing_accounting(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['list_expense', 'create_expense', 'update_expense', 'delete_expense', 'list_accounting']);
        $expenseType = ExpenseType::query()->create([
            'tenant_id' => null,
            'code' => 'ops',
            'name' => 'Operations',
            'is_default' => true,
        ]);

        $created = app(TenantExpenseService::class)->createForCurrentTenant(new TenantExpenseCreate(
            description: 'Office rent',
            amount: 500000,
            expenseTypeId: $expenseType->id,
        ));

        $this->assertSame('Office rent', $created->description);
        $this->assertSame($tenantUser->id, $created->createdBy);

        $this->assertDatabaseHas('tenant_accountings', [
            'tenant_id' => $tenant->id,
            'description' => 'Office rent',
            'transaction_type' => 'outgoing',
            'amount' => '500000.00',
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantExpense',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $tenantUser->id,
            'action' => 'tenant_expense.created',
            'target_type' => 'App\\Models\\CoreModule\\TenantExpense',
            'target_id' => $created->id,
        ]);

        $updated = app(TenantExpenseService::class)->update(new TenantExpenseUpdate(
            expenseId: $created->id,
            code: $created->code,
            updateKey: $created->updateKey,
            description: 'Office rent April',
            amount: 550000,
        ));

        $this->assertSame('Office rent April', $updated->description);
        $this->assertSame('550000.00', $updated->amount);

        $this->assertDatabaseHas('tenant_accountings', [
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantExpense',
            'description' => 'Office rent April',
            'amount' => '550000.00',
        ]);

        $list = app(TenantExpenseService::class)->list();
        $this->assertCount(1, $list->items);

        $accountingList = app(TenantAccountingService::class)->list();
        $this->assertSame('Expense', $accountingList->items[0]->referenceLabel);

        app(TenantExpenseService::class)->delete($created->id);

        $this->assertDatabaseMissing('tenant_expenses', [
            'id' => $created->id,
        ]);
        $this->assertDatabaseMissing('tenant_accountings', [
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantExpense',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'tenant_expense.deleted',
            'target_type' => 'App\\Models\\CoreModule\\TenantExpense',
            'target_id' => $created->id,
        ]);
    }

    public function test_it_creates_updates_and_deletes_capital_with_linked_incoming_accounting(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['dashboard', 'list_capital', 'create_capital', 'update_capital', 'delete_capital', 'list_accounting']);

        $created = app(TenantCapitalService::class)->createForCurrentTenant(new TenantCapitalCreate(
            description: 'Owner cash injection',
            amount: 750000,
        ));

        $this->assertSame('Owner cash injection', $created->description);
        $this->assertSame($tenantUser->id, $created->createdBy);

        $this->assertDatabaseHas('tenant_accountings', [
            'tenant_id' => $tenant->id,
            'description' => 'Owner cash injection',
            'transaction_type' => 'incoming',
            'amount' => '750000.00',
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantCapital',
        ]);

        $summary = app(TenantDashboardService::class)->summary();
        $this->assertSame(750000.0, $summary->financial['cashAvailable']);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $tenantUser->id,
            'action' => 'tenant_capital.created',
            'target_type' => 'App\\Models\\CoreModule\\TenantCapital',
            'target_id' => $created->id,
        ]);

        $updated = app(TenantCapitalService::class)->update(new TenantCapitalUpdate(
            capitalId: $created->id,
            code: $created->code,
            updateKey: $created->updateKey,
            description: 'Owner cash injection April',
            amount: 850000,
        ));

        $this->assertSame('Owner cash injection April', $updated->description);
        $this->assertSame('850000.00', $updated->amount);

        $this->assertDatabaseHas('tenant_accountings', [
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantCapital',
            'description' => 'Owner cash injection April',
            'transaction_type' => 'incoming',
            'amount' => '850000.00',
        ]);

        $list = app(TenantCapitalService::class)->list();
        $this->assertCount(1, $list->items);

        $accountingList = app(TenantAccountingService::class)->list();
        $this->assertSame('Capital', $accountingList->items[0]->referenceLabel);

        app(TenantCapitalService::class)->delete($created->id);

        $this->assertDatabaseMissing('tenant_capitals', [
            'id' => $created->id,
        ]);
        $this->assertDatabaseMissing('tenant_accountings', [
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantCapital',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'tenant_capital.deleted',
            'target_type' => 'App\\Models\\CoreModule\\TenantCapital',
            'target_id' => $created->id,
        ]);
    }

    public function test_it_creates_updates_and_deletes_debt_with_linked_outgoing_accounting(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['list_debt', 'create_debt', 'update_debt', 'delete_debt', 'list_accounting']);

        $created = app(TenantDebtService::class)->createForCurrentTenant(new TenantDebtCreate(
            amount: 125000,
            description: 'Emergency cash debt',
            tag: 'cash-advance',
            createdBy: $tenantUser->id,
        ));

        $this->assertSame('Emergency cash debt', $created->description);
        $this->assertSame('cash-advance', $created->tag);

        $this->assertDatabaseHas('tenant_accountings', [
            'tenant_id' => $tenant->id,
            'description' => 'Emergency cash debt',
            'transaction_type' => 'outgoing',
            'amount' => '125000.00',
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantDebt',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $tenantUser->id,
            'action' => 'tenant_debt.created',
            'target_type' => 'App\\Models\\CoreModule\\TenantDebt',
            'target_id' => $created->id,
        ]);

        $updated = app(TenantDebtService::class)->update(new TenantDebtUpdate(
            debtId: $created->id,
            code: $created->code,
            updateKey: $created->updateKey,
            amount: 130000,
            description: 'Emergency cash debt updated',
            isPaid: true,
        ));

        $this->assertSame('130000.00', $updated->amount);
        $this->assertTrue($updated->isPaid);

        $this->assertDatabaseHas('tenant_accountings', [
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantDebt',
            'description' => 'Emergency cash debt updated',
            'amount' => '130000.00',
        ]);

        $list = app(TenantDebtService::class)->list();
        $this->assertCount(1, $list->items);

        $accountingList = app(TenantAccountingService::class)->list();
        $this->assertSame('Debt', $accountingList->items[0]->referenceLabel);

        app(TenantDebtService::class)->delete($created->id);

        $this->assertDatabaseMissing('tenant_debts', [
            'id' => $created->id,
        ]);
        $this->assertDatabaseMissing('tenant_accountings', [
            'reference_id' => $created->id,
            'reference_type' => 'App\\Models\\CoreModule\\TenantDebt',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'tenant_debt.deleted',
            'target_type' => 'App\\Models\\CoreModule\\TenantDebt',
            'target_id' => $created->id,
        ]);
    }

    public function test_it_builds_accounting_overview_and_searches_transactions(): void
    {
        $tenant = $this->createTenant();
        $tenantUser = $this->actingTenantUser($tenant, ['list_accounting']);
        $accountingService = app(TenantAccountingService::class);

        $accountingService->create(new TenantAccountingCreate(
            description: 'Redeem payment',
            transactionType: 'incoming',
            amount: 1000,
            createdBy: $tenantUser->id,
        ));
        $accountingService->create(new TenantAccountingCreate(
            description: 'Shop expense',
            transactionType: 'outgoing',
            amount: 250,
            createdBy: $tenantUser->id,
        ));

        $overview = $accountingService->overview();
        $searchResult = $accountingService->list(15, 'Redeem');

        $this->assertSame(750.0, $overview->liquidCapital);
        $this->assertSame(1000.0, $overview->monthIncoming);
        $this->assertSame(250.0, $overview->monthOutgoing);
        $this->assertSame(100.0, $overview->incomingProgress);
        $this->assertSame(25.0, $overview->outgoingProgress);
        $this->assertSame(1, $searchResult->total);
        $this->assertSame('Redeem payment', $searchResult->items[0]->description);
    }

    protected function createTenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Finance Owner',
            'email' => 'finance-owner@example.com',
            'phone' => '09111111111',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Finance Tenant',
            'tenant_code' => 'finance-tenant',
            'subdomain' => 'finance-subdomain',
            'status' => 'active',
        ]);
    }

    protected function actingTenantUser(Tenant $tenant, array $permissions): TenantUser
    {
        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Finance Role',
            'description' => 'Finance role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);

        $tenantUser = TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'FIN001',
            'name' => 'Finance User',
            'nrc' => '12/PaTaNa(N)000111',
            'email' => 'finance-user@example.com',
            'phone' => '0955555111',
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($tenantUser);

        return $tenantUser;
    }
}
