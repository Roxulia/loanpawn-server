<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\TenantAccountingCreate;
use App\Enums\AccountingCategory;
use App\Enums\AccountingDayOpeningSource;
use App\Enums\AccountingDayStatus;
use App\Exceptions\AccountingDayClosed;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\TenantAccountingDay;
use App\Models\TenantAccountingTransactions;
use App\Repository\TenantAccountingDayRepository;
use App\Services\TenantModule\TenantAccountingDayService;
use App\Services\TenantModule\TenantAccountingTransactionService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TenantAccountingDayServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_first_transaction_opens_day_and_closed_day_rejects_more_transactions(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Yangon'));
        [$tenant, $user] = $this->actingTenant(['list_accounting', 'close_accounting_day']);

        app(TenantAccountingTransactionService::class)->create(
            new TenantAccountingCreate(
                description: 'Daily expense',
                transactionType: 'outgoing',
                amount: 1000,
                createdBy: $user->id,
            ),
            AccountingCategory::Expense,
        );

        $this->assertDatabaseHas('tenant_accounting_days', [
            'tenant_id' => $tenant->id,
            'business_date' => '2026-08-12',
            'status' => AccountingDayStatus::Open->value,
            'opening_source' => AccountingDayOpeningSource::FirstTransaction->value,
        ]);

        app(TenantAccountingDayService::class)->closeCurrent();

        $this->assertDatabaseHas('tenant_accounting_day_summaries', [
            'tenant_id' => $tenant->id,
            'total_outgoing' => '1000.0000',
            'expense' => '1000.0000',
            'profit' => '-1000.0000',
        ]);

        $this->expectException(AccountingDayClosed::class);
        app(TenantAccountingTransactionService::class)->create(
            new TenantAccountingCreate(
                description: 'Too late',
                transactionType: 'outgoing',
                amount: 100,
                createdBy: $user->id,
            ),
            AccountingCategory::Expense,
        );
    }

    public function test_opening_today_closes_old_day_and_creates_closed_gap_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Yangon'));
        [$tenant] = $this->actingTenant([]);
        TenantAccountingDay::query()->create([
            'tenant_id' => $tenant->id,
            'business_date' => '2026-08-09',
            'timezone' => 'Asia/Yangon',
            'status' => AccountingDayStatus::Open,
            'opened_at' => CarbonImmutable::parse('2026-08-09 09:00:00', 'Asia/Yangon')->utc(),
            'opening_source' => AccountingDayOpeningSource::FirstTransaction,
        ]);

        app(TenantAccountingDayService::class)->ensureOpenForTransaction();

        $this->assertDatabaseCount('tenant_accounting_days', 4);
        $this->assertDatabaseHas('tenant_accounting_days', ['business_date' => '2026-08-09', 'status' => 'CLOSED']);
        $this->assertDatabaseHas('tenant_accounting_days', ['business_date' => '2026-08-10', 'status' => 'CLOSED']);
        $this->assertDatabaseHas('tenant_accounting_days', ['business_date' => '2026-08-11', 'status' => 'CLOSED']);
        $this->assertDatabaseHas('tenant_accounting_days', ['business_date' => '2026-08-12', 'status' => 'OPEN']);
    }

    public function test_day_summary_normalizes_internal_category_enum(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-12 10:00:00', 'Asia/Yangon'));
        [$tenant, $user] = $this->actingTenant([]);
        TenantAccountingTransactions::query()->create([
            'tenant_id' => $tenant->id,
            'business_date' => '2026-08-12',
            'transaction_direction' => 'internal',
            'accounting_category' => AccountingCategory::Internal,
            'amount' => 5000,
            'description' => 'Financial account transfer',
            'occurred_at' => now(),
            'created_by' => $user->id,
            'update_key' => 0,
            'is_deleted' => false,
        ]);

        $summary = app(TenantAccountingDayRepository::class)->summaryData($tenant->id, '2026-08-12')[0];

        $this->assertSame(5000.0, $summary['category_totals']['internal']);
        $this->assertSame(0.0, $summary['closing_balance']);
    }

    private function actingTenant(array $permissions): array
    {
        $owner = PlatformUser::query()->create([
            'code' => 'PU'.random_int(10000000, 99999999),
            'name' => 'Accounting Owner',
            'email' => 'accounting-owner-'.random_int(1, 999999).'@example.com',
            'phone' => '09111111111',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => 'Accounting Tenant',
            'tenant_code' => 'accounting-'.random_int(1000, 9999),
            'subdomain' => 'accounting-'.random_int(1000, 9999),
            'status' => 'active',
        ]);
        $role = TenantRole::query()->create([
            'name' => 'Accounting Test Role',
            'description' => 'Accounting test role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);
        $user = TenantUser::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'code' => 'TU'.random_int(10000000, 99999999),
            'username' => 'ACCT'.random_int(1000, 9999),
            'name' => 'Accounting User',
            'email' => 'accounting-user-'.random_int(1, 999999).'@example.com',
            'phone' => '09222222222',
            'nrc' => '12/TEST(N)'.random_int(100000, 999999),
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($user);

        return [$tenant, $user];
    }
}
