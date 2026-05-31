<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\TenantUserCreate;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\TenantModule\TenantUserService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantUserServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_username_from_name_initials_and_reversed_phone_digits(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();

        $detail = app(TenantUserService::class)->create(new TenantUserCreate(
            tenantId: $tenant->id,
            name: 'zin lin htet',
            nrc: '12/PaTaNa(N)123456',
            phone: '09250647303',
            password: 'secret123',
            email: 'zlh@example.com',
            address: 'Yangon',
        ));

        $this->assertSame('ZLH30374', $detail->username);

        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'name' => 'zin lin htet',
            'username' => 'ZLH30374',
            'phone' => '09250647303',
            'nrc' => '12/PaTaNa(N)123456',
            'address' => 'Yangon',
        ]);
    }

    public function test_it_appends_numeric_suffix_when_generated_username_already_exists(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();

        app(TenantUserService::class)->create(new TenantUserCreate(
            tenantId: $tenant->id,
            name: 'zin lin htet',
            nrc: '12/PaTaNa(N)123456',
            phone: '09250647303',
            password: 'secret123',
            email: 'first@example.com',
        ));

        $detail = app(TenantUserService::class)->create(new TenantUserCreate(
            tenantId: $tenant->id,
            name: 'zin lin htet',
            nrc: '12/PaTaNa(N)654321',
            phone: '01234647303',
            password: 'secret123',
            email: 'second@example.com',
        ));

        $this->assertSame('ZLH30371', $detail->username);
    }

    public function test_it_resets_password_to_default_and_logs_out_target_when_requested(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $admin = $this->createTenantUser($tenant, 'admin@example.com', ['update_user_all']);
        $target = $this->createTenantUser($tenant, 'target@example.com', []);
        $target->createToken('target-token');
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($admin);

        app(TenantUserService::class)->resetPasswordToDefault($target->id, true);

        $target->refresh();
        $this->assertTrue(Hash::check('12345678', $target->password));
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_it_keeps_target_sessions_when_reset_logout_from_all_is_false(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $admin = $this->createTenantUser($tenant, 'admin@example.com', ['update_user_all']);
        $target = $this->createTenantUser($tenant, 'target@example.com', []);
        $target->createToken('target-token');
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($admin);

        app(TenantUserService::class)->resetPasswordToDefault($target->id, false);

        $target->refresh();
        $this->assertTrue(Hash::check('12345678', $target->password));
        $this->assertSame(1, $target->tokens()->count());
    }

    public function test_current_user_password_change_logs_out_current_user_from_all_devices(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $user = $this->createTenantUser($tenant, 'user@example.com', []);
        $user->createToken('user-token');
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($user);

        app(TenantUserService::class)->changeCurrentUserPassword('secret123', 'changed123');

        $user->refresh();
        $this->assertTrue(Hash::check('changed123', $user->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    protected function createTenant(): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Tenant Owner',
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

    protected function createUserRole(): TenantRole
    {
        return TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'User',
            'description' => 'Default user role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.User.permissions'),
        ]);
    }

    protected function createTenantUser(Tenant $tenant, string $email, array $permissions): TenantUser
    {
        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Role '.str_replace(['@', '.'], '-', $email),
            'description' => 'Test role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);

        return TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => substr(md5($email), 0, 8),
            'name' => 'Test User',
            'nrc' => substr(md5($email.'nrc'), 0, 20),
            'email' => $email,
            'phone' => '09'.substr((string) abs(crc32($email)), 0, 8),
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'is_deleted' => false,
        ]);
    }
}
