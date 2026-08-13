<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\Currency;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTypes;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyOperationAccountBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_changes_without_writing(): void
    {
        [$tenant, $defaultAccount, $slipId] = $this->legacyTenant('backfill-dry');

        $this->artisan('accounting:backfill-operation-accounts')
            ->expectsOutputToContain('DRY-RUN mode')
            ->expectsOutputToContain('Loan contract account')
            ->assertSuccessful();

        $this->assertDatabaseHas('pawn_loan_contract_slips', ['id' => $slipId, 'account_id' => null]);
        $this->assertDatabaseHas('financial_accounts', ['id' => $defaultAccount->id, 'tenant_id' => $tenant->id]);
    }

    public function test_apply_backfills_paid_fields_preserves_existing_values_and_is_idempotent(): void
    {
        [$tenant, $defaultAccount, $slipId] = $this->legacyTenant('backfill-apply');
        $otherAccount = $this->account($tenant, false, 'OTHER');

        $paidInterestId = $this->interest($tenant->id, $slipId, true);
        $unpaidInterestId = $this->interest($tenant->id, $slipId, false);
        $preservedInterestId = $this->interest($tenant->id, $slipId, true, $otherAccount->id, $otherAccount->id);
        $redemptionId = $this->redemption($tenant->id, $slipId);
        $paidDebtId = $this->debt($tenant->id, true);
        $unpaidDebtId = $this->debt($tenant->id, false);

        $this->artisan('accounting:backfill-operation-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('pawn_loan_contract_slips', ['id' => $slipId, 'account_id' => $defaultAccount->id]);
        $this->assertDatabaseHas('pawn_interest_payments', ['id' => $paidInterestId, 'created_account_id' => $defaultAccount->id, 'accept_account_id' => $defaultAccount->id]);
        $this->assertDatabaseHas('pawn_interest_payments', ['id' => $unpaidInterestId, 'created_account_id' => $defaultAccount->id, 'accept_account_id' => null]);
        $this->assertDatabaseHas('pawn_interest_payments', ['id' => $preservedInterestId, 'created_account_id' => $otherAccount->id, 'accept_account_id' => $otherAccount->id]);
        $this->assertDatabaseHas('pawn_redemptions', ['id' => $redemptionId, 'account_id' => $defaultAccount->id]);
        $this->assertDatabaseHas('tenant_debts', ['id' => $paidDebtId, 'created_account_id' => $defaultAccount->id, 'accept_account_id' => $defaultAccount->id]);
        $this->assertDatabaseHas('tenant_debts', ['id' => $unpaidDebtId, 'created_account_id' => $defaultAccount->id, 'accept_account_id' => null]);

        $this->artisan('accounting:backfill-operation-accounts', ['--apply' => true])
            ->expectsOutputToContain('Fields populated')
            ->assertSuccessful();
    }

    /** @return array{Tenant, FinancialAccount, int} */
    private function legacyTenant(string $code): array
    {
        $owner = PlatformUser::query()->create([
            'code' => 'PU'.random_int(100000, 999999),
            'name' => 'Legacy Owner',
            'email' => $code.'@example.com',
            'phone' => '09'.random_int(100000000, 999999999),
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => 'Legacy Tenant',
            'tenant_code' => $code,
            'status' => 'active',
        ]);
        $defaultAccount = $this->account($tenant, true, 'DEFAULT');
        $customerId = DB::table('tenant_customers')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'C-'.$tenant->id,
            'name' => 'Legacy Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slipId = DB::table('pawn_loan_contract_slips')->insertGetId([
            'tenant_id' => $tenant->id,
            'slip_no' => 'S-'.$tenant->id,
            'customer_id' => $customerId,
            'loan_amount' => 100,
            'interest_rate' => 10,
            'status' => 'redeemed',
            'expiry_quota' => 1,
            'expiry_quota_type' => 'month',
            'deleted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $defaultAccount, $slipId];
    }

    private function account(Tenant $tenant, bool $isDefault, string $suffix): FinancialAccount
    {
        $type = FinancialAccountTypes::query()->firstOrCreate(
            ['tenant_id' => null, 'code' => 'cash'],
            ['name' => 'Cash', 'is_active' => true]
        );
        $currency = Currency::query()->firstOrCreate(
            ['scope_key' => 'platform', 'code' => 'MMK'],
            ['tenant_id' => null, 'name' => 'Myanmar Kyat', 'decimal_precision' => 2, 'rounding_mode' => 'HALF_UP', 'is_default' => true, 'is_active' => true]
        );

        return FinancialAccount::query()->create([
            'tenant_id' => $tenant->id,
            'account_type_id' => $type->id,
            'currency_id' => $currency->id,
            'account_name' => $suffix.' Account',
            'account_code' => $suffix.'-'.$tenant->id,
            'balance' => 0,
            'is_active' => true,
            'is_default' => $isDefault,
            'is_deleted' => false,
            'allow_negative_balance' => false,
        ]);
    }

    private function interest(int $tenantId, int $slipId, bool $paid, ?int $createdAccountId = null, ?int $acceptAccountId = null): int
    {
        return DB::table('pawn_interest_payments')->insertGetId([
            'tenant_id' => $tenantId, 'slip_id' => $slipId,
            'created_account_id' => $createdAccountId, 'accept_account_id' => $acceptAccountId,
            'payment_amount' => $paid ? 10 : 0, 'change_amount' => 0, 'calculated_interest' => 10,
            'is_paid' => $paid, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function redemption(int $tenantId, int $slipId): int
    {
        return DB::table('pawn_redemptions')->insertGetId([
            'tenant_id' => $tenantId, 'slip_number' => 'R-'.$slipId, 'slip_id' => $slipId,
            'gross_amount' => 100, 'net_amount' => 100, 'interest_amount' => 0,
            'received_amount' => 100, 'change_amount' => 0, 'redemption_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function debt(int $tenantId, bool $paid): int
    {
        return DB::table('tenant_debts')->insertGetId([
            'tenant_id' => $tenantId, 'code' => 'D-'.random_int(100000, 999999),
            'amount' => 10, 'description' => 'Legacy debt', 'is_paid' => $paid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
