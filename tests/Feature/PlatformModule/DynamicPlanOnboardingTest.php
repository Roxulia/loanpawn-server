<?php

namespace Tests\Feature\PlatformModule;

use App\Models\CoreModule\TenantRole;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\TenantCategory;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DynamicPlanOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_budgeting_category_has_accounting_only_features(): void
    {
        $this->seed(PackageSeeder::class);

        $category = TenantCategory::query()->where('code', 'budgeting')->firstOrFail();
        $this->assertSame(3, $category->packages()->count());
        $this->assertTrue((bool) $category->packages()->where('code', 'budgeting-trial')->value('is_active'));
        $this->assertFalse((bool) $category->packages()->where('code', 'budgeting-basic')->value('is_active'));
        $this->assertFalse((bool) $category->packages()->where('code', 'budgeting-premium')->value('is_active'));

        foreach ($category->packages as $plan) {
            $enabledCodes = $plan->features()
                ->wherePivot('is_enabled', true)
                ->orderBy('features.code')
                ->pluck('features.code')
                ->all();
            $this->assertSame([
                'accounting_management',
                'capital_management',
                'debt_management',
                'expense_management',
            ], $enabledCodes);
        }
    }

    public function test_registration_verification_logs_user_in_and_redirects_to_tenant_creation(): void
    {
        $user = PlatformUser::query()->create([
            'code' => 'PU00000001',
            'name' => 'New Owner',
            'email' => 'new-owner@example.com',
            'password' => 'Password@123',
            'status' => 'pending_verification',
        ]);
        DB::table('platform_user_email_verification_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson(route('platform.register.verify-code'), [
            'email' => $user->email,
            'otp' => '123456',
        ])->assertOk()->assertJsonPath('data.redirect', route('platform.tenants.create'));

        $this->assertAuthenticatedAs($user, 'platformuser');
    }

    public function test_budgeting_tenant_starts_on_four_month_trial(): void
    {
        Carbon::setTestNow('2026-08-06 12:00:00');
        $this->seed(PackageSeeder::class);
        TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Owner',
            'description' => 'Default owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $user = PlatformUser::query()->create([
            'code' => 'PU00000002',
            'name' => 'Budget Owner',
            'email' => 'budget@example.com',
            'password' => 'Password@123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $category = TenantCategory::query()->where('code', 'budgeting')->firstOrFail();
        $trial = Package::query()->where('code', 'budgeting-trial')->firstOrFail();

        $this->actingAs($user, 'platformuser')->post(route('platform.tenants.store'), [
            'category_id' => $category->id,
            'plan_id' => $trial->id,
            'name' => 'My Budget',
        ])->assertRedirect(route('platform.tenants.create'));

        $tenantId = DB::table('tenants')->where('name', 'My Budget')->value('id');
        $this->assertDatabaseHas('tenants', ['id' => $tenantId, 'category_id' => $category->id]);
        $this->assertDatabaseHas('tenant_licenses', [
            'tenant_id' => $tenantId,
            'plan_id' => $trial->id,
            'plan_type' => 'budgeting-trial',
            'expires_at' => '2026-12-06 12:00:00',
        ]);
        Carbon::setTestNow();
    }
}
