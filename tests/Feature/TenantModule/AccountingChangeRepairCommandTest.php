<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\TenantDebt;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnRedemption;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Support\TenantScopedCacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingChangeRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_default_and_does_not_write(): void
    {
        [$tenant, $slipId] = $this->tenantAndSlip('repair-dry');
        $interestId = $this->interest($tenant, $slipId, '100.00', '20.00');
        $outgoingId = $this->accounting($tenant, PawnInterestPayment::class, $interestId, 'outgoing', '20.00', 'Interest Payment Change Transaction');
        $this->accounting($tenant, PawnInterestPayment::class, $interestId, 'incoming', '80.00', 'Interest Payment Transaction');

        $this->artisan('accounting:repair-change')
            ->expectsOutputToContain('DRY-RUN mode')
            ->expectsOutputToContain('Repaired')
            ->assertSuccessful();

        $this->assertDatabaseHas('tenant_accountings', ['id' => $outgoingId, 'is_deleted' => false]);
    }

    public function test_interest_repair_handles_bug_correct_and_ambiguous_patterns_idempotently(): void
    {
        [$tenant, $slipId] = $this->tenantAndSlip('repair-interest');

        $bugId = $this->interest($tenant, $slipId, '100.00', '20.00');
        $this->accounting($tenant, 'InterestPayment', $bugId, 'incoming', '80.00', 'Interest Payment Transaction');
        $bugOutgoingId = $this->accounting($tenant, 'InterestPayment', $bugId, 'outgoing', '20.00', 'Interest Payment Change Transaction');

        $correctId = $this->interest($tenant, $slipId, '100.00', '20.00');
        $this->accounting($tenant, PawnInterestPayment::class, $correctId, 'incoming', '100.00', 'Interest Payment Transaction');
        $correctOutgoingId = $this->accounting($tenant, PawnInterestPayment::class, $correctId, 'outgoing', '20.00', 'Interest Payment Change Transaction');

        $ambiguousId = $this->interest($tenant, $slipId, '100.00', '20.00');
        $this->accounting($tenant, PawnInterestPayment::class, $ambiguousId, 'incoming', '70.00', 'Interest Payment Transaction');
        $ambiguousOutgoingId = $this->accounting($tenant, PawnInterestPayment::class, $ambiguousId, 'outgoing', '20.00', 'Interest Payment Change Transaction');

        $cacheKeys = app(TenantScopedCacheKeys::class);
        $versionBefore = $cacheKeys->currentVersion('tenant-accounting-list', tenantId: $tenant->id);

        $this->artisan('accounting:repair-change', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('tenant_accountings', ['id' => $bugOutgoingId, 'is_deleted' => true]);
        $this->assertDatabaseHas('tenant_accountings', ['id' => $correctOutgoingId, 'is_deleted' => false]);
        $this->assertDatabaseHas('tenant_accountings', ['id' => $ambiguousOutgoingId, 'is_deleted' => false]);
        $this->assertSame($versionBefore + 1, $cacheKeys->currentVersion('tenant-accounting-list', tenantId: $tenant->id));

        $accountingCount = DB::table('tenant_accountings')->count();
        $this->artisan('accounting:repair-change', ['--apply' => true])->assertSuccessful();
        $this->assertSame($accountingCount, DB::table('tenant_accountings')->count());
        $this->assertDatabaseHas('tenant_accountings', ['id' => $bugOutgoingId, 'is_deleted' => true]);
    }

    public function test_debt_repair_adds_change_only_for_single_gross_payment(): void
    {
        [$tenant] = $this->tenantAndSlip('repair-debt');

        $netDebt = $this->debt($tenant, '80.00', 'D-NET');
        $this->accounting($tenant, TenantDebt::class, $netDebt, 'incoming', '80.00', 'Payment for debt: net');

        $grossDebt = $this->debt($tenant, '80.00', 'D-GROSS');
        $this->accounting($tenant, 'Debt', $grossDebt, 'incoming', '100.00', 'Debt Payment Transaction');

        $ambiguousDebt = $this->debt($tenant, '80.00', 'D-MULTI');
        $this->accounting($tenant, TenantDebt::class, $ambiguousDebt, 'incoming', '50.00', 'Debt Payment Transaction');
        $this->accounting($tenant, TenantDebt::class, $ambiguousDebt, 'incoming', '50.00', 'Debt Payment Transaction');

        $this->artisan('accounting:repair-change', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('tenant_accountings', [
            'reference_type' => 'Debt',
            'reference_id' => $grossDebt,
            'transaction_type' => 'outgoing',
            'amount' => '20.00',
            'is_deleted' => false,
        ]);
        $this->assertDatabaseMissing('tenant_accountings', [
            'reference_id' => $ambiguousDebt,
            'transaction_type' => 'outgoing',
        ]);
    }

    public function test_redemption_repair_handles_all_supported_patterns(): void
    {
        [$tenant, $slipId] = $this->tenantAndSlip('repair-redemption');

        $netBug = $this->redemption($tenant, $slipId, '100.00', '20.00');
        $this->accounting($tenant, PawnRedemption::class, $netBug, 'incoming', '80.00', 'Redemption Transaction');
        $badOutgoingId = $this->accounting($tenant, PawnRedemption::class, $netBug, 'outgoing', '20.00', 'Redemption Change Transaction');

        $grossMissing = $this->redemption($tenant, $slipId, '100.00', '20.00');
        $this->accounting($tenant, 'Redemption', $grossMissing, 'incoming', '100.00', 'Redemption Transaction');

        $correct = $this->redemption($tenant, $slipId, '100.00', '20.00');
        $this->accounting($tenant, PawnRedemption::class, $correct, 'incoming', '100.00', 'Redemption Transaction');
        $correctOutgoingId = $this->accounting($tenant, PawnRedemption::class, $correct, 'outgoing', '20.00', 'Redemption Change Transaction');

        $this->artisan('accounting:repair-change', ['--apply' => true])->assertSuccessful();

        $this->assertDatabaseHas('tenant_accountings', ['id' => $badOutgoingId, 'is_deleted' => true]);
        $this->assertDatabaseHas('tenant_accountings', [
            'reference_type' => 'Redemption',
            'reference_id' => $grossMissing,
            'transaction_type' => 'outgoing',
            'amount' => '20.00',
            'is_deleted' => false,
        ]);
        $this->assertDatabaseHas('tenant_accountings', ['id' => $correctOutgoingId, 'is_deleted' => false]);
    }

    /** @return array{Tenant, int} */
    private function tenantAndSlip(string $tenantCode): array
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.random_int(100000, 999999),
            'name' => 'Repair Owner',
            'email' => $tenantCode.'@example.com',
            'phone' => '09'.random_int(100000000, 999999999),
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Repair Tenant',
            'tenant_code' => $tenantCode,
            'status' => 'active',
        ]);
        $customerId = DB::table('tenant_customers')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'C-'.$tenant->id,
            'name' => 'Repair Customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $slipId = DB::table('pawn_loan_contract_slips')->insertGetId([
            'tenant_id' => $tenant->id,
            'slip_no' => 'S-'.$tenant->id,
            'customer_id' => $customerId,
            'loan_amount' => '100.00',
            'interest_rate' => '10.0000',
            'status' => 'redeemed',
            'expiry_quota' => 1,
            'expiry_quota_type' => 'month',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $slipId];
    }

    private function interest(Tenant $tenant, int $slipId, string $payment, string $change): int
    {
        return DB::table('pawn_interest_payments')->insertGetId([
            'tenant_id' => $tenant->id,
            'slip_id' => $slipId,
            'payment_amount' => $payment,
            'change_amount' => $change,
            'calculated_interest' => '80.00',
            'payment_at' => now(),
            'is_paid' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function debt(Tenant $tenant, string $amount, string $code): int
    {
        return DB::table('tenant_debts')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'amount' => $amount,
            'description' => 'Repair debt',
            'is_paid' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function redemption(Tenant $tenant, int $slipId, string $received, string $change): int
    {
        return DB::table('pawn_redemptions')->insertGetId([
            'tenant_id' => $tenant->id,
            'slip_number' => 'R-'.random_int(100000, 999999),
            'slip_id' => $slipId,
            'gross_amount' => '80.00',
            'net_amount' => '80.00',
            'interest_amount' => '0.00',
            'received_amount' => $received,
            'change_amount' => $change,
            'redemption_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function accounting(
        Tenant $tenant,
        string $referenceType,
        int $referenceId,
        string $transactionType,
        string $amount,
        string $description,
    ): int {
        return DB::table('tenant_accountings')->insertGetId([
            'tenant_id' => $tenant->id,
            'description' => $description,
            'transaction_type' => $transactionType,
            'amount' => $amount,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
