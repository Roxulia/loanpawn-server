<?php

namespace Tests\Support;

use App\DataObjects\RequestObjects\LoanContractSlipCreate;
use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\Models\CoreModule\{Currency, InterestType, TenantRole, TenantSetting, TenantUser};
use App\Models\{FinancialAccount, FinancialAccountTypes};
use App\Models\PlatformModule\{PlatformUser, Tenant, TenantLicense};
use App\Services\PawnModule\LoanContractServices\ManagementService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\{Auth, DB};

trait PawnInventoryFixture
{
    protected Tenant $tenant;
    protected FinancialAccount $account;
    protected InterestType $interest;

    protected function provisionPawnTenant(): void
    {
        // Unique tenant references coexist with pre-existing disposable-database fixtures.
        $suffix = bin2hex(random_bytes(4));
        $owner = PlatformUser::query()->create(['code' => 'PU'.$suffix, 'name' => 'Inventory Test Owner',
            'email' => $suffix.'@inventory.test', 'phone' => '099'.$suffix, 'password' => 'secret123', 'status' => 'active']);
        $this->tenant = Tenant::query()->create(['platform_user_id' => $owner->id, 'name' => 'Inventory Test',
            'tenant_code' => 'it'.$suffix, 'subdomain' => 'it'.$suffix, 'status' => 'active']);
        app(TenantContext::class)->set($this->tenant);
        $role = TenantRole::query()->create(['name' => 'Inventory Test '.$suffix, 'is_default' => false, 'permissions' => ['access_all']]);
        $user = TenantUser::query()->create(['code' => 'TU'.$suffix, 'tenant_id' => $this->tenant->id,
            'role_id' => $role->id, 'username' => $suffix, 'name' => 'Inventory Test User',
            'nrc' => '12/PaTaNa(N)'.$suffix, 'email' => 'user'.$suffix.'@inventory.test',
            'phone' => '095'.$suffix, 'password' => 'secret123', 'status' => 'active', 'is_deleted' => false]);
        Auth::guard('tenantuser')->login($user);
        $currency = Currency::query()->firstOrCreate(['scope_key' => 'platform', 'code' => 'MMK'],
            ['tenant_id' => null, 'name' => 'Myanmar Kyat', 'decimal_precision' => 2, 'rounding_mode' => 'HALF_UP', 'is_active' => true]);
        TenantSetting::query()->create(['tenant_id' => $this->tenant->id, 'key' => 'currency_preferences',
            'category' => 'finance', 'default_currency_id' => $currency->id, 'reporting_currency_id' => $currency->id, 'update_key' => 0]);
        $type = FinancialAccountTypes::query()->firstOrCreate(['tenant_id' => null, 'code' => 'cash'], ['name' => 'Cash', 'is_active' => true]);
        $this->account = FinancialAccount::query()->create(['tenant_id' => $this->tenant->id, 'account_type_id' => $type->id,
            'currency_id' => $currency->id, 'account_name' => 'Test Cash', 'account_code' => 'IT-'.$suffix,
            'balance' => 1000000, 'is_active' => true, 'is_default' => true, 'is_deleted' => false, 'allow_negative_balance' => true]);
        DB::table('financial_account_assignments')->insert(['tenant_id' => $this->tenant->id, 'financial_account_id' => $this->account->id,
            'assigned_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->interest = InterestType::query()->firstOrCreate(['tenant_id' => null, 'code' => 'monthly'],
            ['name' => 'Monthly', 'duration_in_days' => 30, 'is_default' => true]);
    }

    protected function createPawnSlip(array $items = [])
    {
        // Exercise the real Pawn orchestration, including customer, finance, interest and custody.
        return app(ManagementService::class)->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(name: 'Integration Customer', phone: '09912345678'),
            collateralItems: $items ?: [new PawnCollateralItemCreate(type: 'Normal', name: 'Same model phone', quantity: 2, itemStatus: 'active')],
            loanAmount: 10000, interestRate: 5, accountId: $this->account->id,
            interestTypeId: $this->interest->id, expiryQuota: 3, expiryQuotaType: 'Month',
        ));
    }
}
