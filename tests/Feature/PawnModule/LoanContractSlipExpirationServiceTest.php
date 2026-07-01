<?php

namespace Tests\Feature\PawnModule;

use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\PawnModule\LoanContractServices\ExpirationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LoanContractSlipExpirationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-20 23:59:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_check_expire_marks_only_overdue_active_slips_as_expired(): void
    {
        $tenant = $this->tenant();
        $overdueSlipId = $this->slip($tenant, 'OVERDUE', now()->subDay()->toDateString(), 'active');
        $todaySlipId = $this->slip($tenant, 'TODAY', now()->toDateString(), 'active');
        $futureSlipId = $this->slip($tenant, 'FUTURE', now()->addDay()->toDateString(), 'active');
        $redeemedSlipId = $this->slip($tenant, 'REDEEMED', now()->subDay()->toDateString(), 'redeemed');
        $deletedSlipId = $this->slip($tenant, 'DELETED', now()->subDay()->toDateString(), 'active', true);

        $updatedCount = app(ExpirationService::class)->checkExpire();

        $this->assertSame(1, $updatedCount);
        $this->assertDatabaseHas('pawn_loan_contract_slips', ['id' => $overdueSlipId, 'status' => 'expired']);
        $this->assertDatabaseHas('pawn_loan_contract_slips', ['id' => $todaySlipId, 'status' => 'active']);
        $this->assertDatabaseHas('pawn_loan_contract_slips', ['id' => $futureSlipId, 'status' => 'active']);
        $this->assertDatabaseHas('pawn_loan_contract_slips', ['id' => $redeemedSlipId, 'status' => 'redeemed']);
        $this->assertDatabaseHas('pawn_loan_contract_slips', ['id' => $deletedSlipId, 'status' => 'active']);
    }

    protected function tenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Pawn Owner',
            'email' => 'pawn-owner@example.com',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Pawn Tenant',
            'tenant_code' => 'pawn-tenant',
            'subdomain' => 'pawn-tenant',
            'status' => 'active',
        ]);
    }

    protected function slip(Tenant $tenant, string $slipNo, string $expireDate, string $status, bool $isDeleted = false): int
    {
        $customerId = DB::table('tenant_customers')->insertGetId([
            'tenant_id' => $tenant->id,
            'code' => 'CUS'.$slipNo,
            'name' => 'Customer '.$slipNo,
            'phone' => '09'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('pawn_loan_contract_slips')->insertGetId([
            'tenant_id' => $tenant->id,
            'slip_no' => $slipNo,
            'customer_id' => $customerId,
            'loan_amount' => 500,
            'interest_rate' => 5,
            'created_at' => now()->subMonth(),
            'expire_at' => Carbon::parse($expireDate)->startOfDay(),
            'status' => $status,
            'is_deleted' => $isDeleted,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
