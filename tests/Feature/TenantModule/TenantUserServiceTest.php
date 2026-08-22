<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\TenantUserCreate;
use App\DataObjects\RequestObjects\TenantUserUpdate;
use App\Exceptions\TenantUserAccessDenied;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Repository\TenantUserRepository;
use App\Services\TenantModule\TenantUserService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantUserServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_find_methods_exclude_soft_deleted_users(): void
    {
        $tenant = $this->createTenant();
        $role = $this->createUserRole();
        $user = $this->createTenantUserWithRole($tenant, $role, 'deleted-lookup@example.com');

        $user->update(['is_deleted' => true]);
        app(TenantContext::class)->set($tenant);

        $repository = app(TenantUserRepository::class);

        $this->assertNull($repository->findByEmail($user->email));
        $this->assertNull($repository->findByTenantIdAndEmail($tenant->id, $user->email));
        $this->assertNull($repository->findById($user->id));
        $this->assertNull($repository->findByCode($user->code));
        $this->assertNull($repository->findByIdWithLock($user->id));
        $this->assertNull($repository->findByCodeWithLock($user->code));
    }

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
        $this->assertSame('User', $detail->roleName);
        $this->assertSame('User', $detail->toArray()['role_name']);

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

    public function test_it_reuses_email_phone_and_nrc_after_staff_is_soft_deleted(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();

        $first = app(TenantUserService::class)->create(new TenantUserCreate(
            tenantId: $tenant->id,
            name: 'Deleted Staff',
            nrc: '12/PaTaNa(N)123456',
            phone: '09250647303',
            password: 'secret123',
            email: 'deleted@example.com',
        ));

        TenantUser::query()->withoutGlobalScope('tenant')
            ->where('username', $first->username)
            ->update(['is_deleted' => true, 'status' => 'inactive']);

        $replacement = app(TenantUserService::class)->create(new TenantUserCreate(
            tenantId: $tenant->id,
            name: 'Deleted Staff',
            nrc: '12/PaTaNa(N)123456',
            phone: '09250647303',
            password: 'secret123',
            email: 'deleted@example.com',
        ));

        $this->assertNotSame($first->username, $replacement->username);
        $this->assertDatabaseCount('tenant_users', 2);
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $tenant->id,
            'username' => $replacement->username,
            'email' => 'deleted@example.com',
            'phone' => '09250647303',
            'nrc' => '12/PaTaNa(N)123456',
            'is_deleted' => false,
        ]);
    }

    public function test_only_owner_role_can_use_admin_management_permissions(): void
    {
        $tenant = $this->createTenant();
        $ownerRole = TenantRole::query()->create([
            'name' => 'Owner',
            'description' => 'Owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $adminRole = TenantRole::query()->create([
            'name' => 'Admin',
            'description' => 'Admin role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Admin.permissions'),
        ]);
        $owner = $this->createTenantUserWithRole($tenant, $ownerRole, 'owner-staff@example.com');
        $admin = $this->createTenantUserWithRole($tenant, $adminRole, 'admin-staff@example.com');
        app(TenantContext::class)->set($tenant);

        Auth::guard('tenantuser')->login($owner);
        $permissionService = app(TenantUserPermissionService::class);
        $permissionService->authorizeAdminUserCreate();
        $permissionService->authorizeAdminUserUpdate();
        $permissionService->authorizeAdminUserDelete();

        Auth::guard('tenantuser')->login($admin);

        $this->expectException(TenantUserAccessDenied::class);
        $permissionService->authorizeAdminUserUpdate();
    }

    public function test_it_resets_password_to_default_and_logs_out_target_when_requested(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $admin = $this->createTenantUser($tenant, 'admin@example.com', ['update_user_info']);
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
        $admin = $this->createTenantUser($tenant, 'admin@example.com', ['update_user_info']);
        $target = $this->createTenantUser($tenant, 'target@example.com', []);
        $target->createToken('target-token');
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($admin);

        app(TenantUserService::class)->resetPasswordToDefault($target->id, false);

        $target->refresh();
        $this->assertTrue(Hash::check('12345678', $target->password));
        $this->assertSame(1, $target->tokens()->count());
    }

    public function test_owner_can_reset_only_their_own_password_to_default(): void
    {
        $tenant = $this->createTenant();
        $ownerRole = TenantRole::query()->create([
            'name' => 'Owner',
            'description' => 'Owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $owner = $this->createTenantUserWithRole($tenant, $ownerRole, 'owner-reset@example.com');
        $owner->createToken('owner-token');
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($owner);

        app(TenantUserService::class)->resetPasswordToDefault($owner->id, true);

        $owner->refresh();
        $this->assertTrue(Hash::check('12345678', $owner->password));
        $this->assertSame(0, $owner->tokens()->count());
    }

    public function test_other_users_cannot_reset_an_owner_password(): void
    {
        $tenant = $this->createTenant();
        $ownerRole = TenantRole::query()->create([
            'name' => 'Owner',
            'description' => 'Owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $owner = $this->createTenantUserWithRole($tenant, $ownerRole, 'protected-owner@example.com');
        $manager = $this->createTenantUser($tenant, 'manager@example.com', ['update_user_info']);
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($manager);

        $this->expectException(TenantUserAccessDenied::class);

        app(TenantUserService::class)->resetPasswordToDefault($owner->id, true);
    }

    public function test_platform_owner_cannot_bypass_owner_self_reset_protection(): void
    {
        $tenant = $this->createTenant();
        $ownerRole = TenantRole::query()->create([
            'name' => 'Owner',
            'description' => 'Owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $owner = $this->createTenantUserWithRole($tenant, $ownerRole, 'platform-protected-owner@example.com');
        app(TenantContext::class)->set($tenant);
        Auth::guard('platformuser')->login($tenant->owner);

        $this->expectException(TenantUserAccessDenied::class);

        app(TenantUserService::class)->resetPasswordToDefault($owner->id, true);
    }

    public function test_admin_password_reset_requires_owner_admin_update_permission(): void
    {
        $tenant = $this->createTenant();
        $adminRole = TenantRole::query()->create([
            'name' => 'Admin',
            'description' => 'Admin role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Admin.permissions'),
        ]);
        $admin = $this->createTenantUserWithRole($tenant, $adminRole, 'admin-reset-target@example.com');
        $manager = $this->createTenantUser($tenant, 'user-manager@example.com', ['update_user_info']);
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($manager);

        $this->expectException(TenantUserAccessDenied::class);

        app(TenantUserService::class)->resetPasswordToDefault($admin->id, true);
    }

    public function test_owner_with_admin_update_permission_can_reset_admin_password(): void
    {
        $tenant = $this->createTenant();
        $ownerRole = TenantRole::query()->create([
            'name' => 'Owner',
            'description' => 'Owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $adminRole = TenantRole::query()->create([
            'name' => 'Admin',
            'description' => 'Admin role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Admin.permissions'),
        ]);
        $owner = $this->createTenantUserWithRole($tenant, $ownerRole, 'admin-reset-owner@example.com');
        $admin = $this->createTenantUserWithRole($tenant, $adminRole, 'admin-reset-success@example.com');
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($owner);

        app(TenantUserService::class)->resetPasswordToDefault($admin->id, false);

        $this->assertTrue(Hash::check('12345678', $admin->refresh()->password));
    }

    public function test_owner_permissions_cannot_be_changed(): void
    {
        $tenant = $this->createTenant();
        $ownerRole = TenantRole::query()->create([
            'name' => 'Owner',
            'description' => 'Owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $owner = $this->createTenantUserWithRole($tenant, $ownerRole, 'permission-protected-owner@example.com');
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($owner);

        $this->expectException(TenantUserAccessDenied::class);

        app(TenantUserService::class)->updatePermissions($owner->id, ['dashboard' => false]);
    }

    public function test_owner_can_edit_own_profile_but_cannot_change_owner_role(): void
    {
        $tenant = $this->createTenant();
        $ownerRole = TenantRole::query()->create([
            'name' => 'Owner',
            'description' => 'Owner role',
            'is_default' => true,
            'permissions' => config('tenant_permissions.roles.Owner.permissions'),
        ]);
        $userRole = $this->createUserRole();
        $owner = $this->createTenantUserWithRole($tenant, $ownerRole, 'owner-profile@example.com');
        $owner->update(['update_key' => 0]);
        $owner->refresh();
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($owner);
        $service = app(TenantUserService::class);

        $updated = $service->update(new TenantUserUpdate(
            userId: $owner->id,
            code: $owner->code,
            updateKey: $owner->update_key,
            name: 'Updated Owner',
        ));

        $this->assertSame('Updated Owner', $updated->name);

        $this->expectException(TenantUserAccessDenied::class);

        $service->update(new TenantUserUpdate(
            userId: $owner->id,
            code: $owner->code,
            updateKey: $updated->updateKey,
            roleId: $userRole->id,
        ));
    }

    public function test_permission_assignment_requires_target_update_and_assignment_permissions(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $manager = $this->createTenantUser($tenant, 'permission-manager@example.com', ['update_user_info']);
        $target = $this->createTenantUser($tenant, 'permission-target@example.com', []);
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($manager);

        $this->expectException(TenantUserAccessDenied::class);

        app(TenantUserPermissionService::class)->authorizePermissionAssignment($target);
    }

    public function test_financial_assignment_requires_target_update_and_assignment_permissions(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $manager = $this->createTenantUser($tenant, 'financial-manager@example.com', ['manage_financial_account_assignments']);
        $target = $this->createTenantUser($tenant, 'financial-target@example.com', []);
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($manager);

        $this->expectException(TenantUserAccessDenied::class);

        app(TenantUserPermissionService::class)->authorizeFinancialAccountAssignment($target);
    }

    public function test_access_all_cannot_bypass_self_assignment_prohibitions(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $user = $this->createTenantUser($tenant, 'access-all-self@example.com', ['access_all']);
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($user);

        $this->expectException(TenantUserAccessDenied::class);

        app(TenantUserPermissionService::class)->authorizeRoleAssignment($user, false);
    }

    public function test_permission_assignment_succeeds_when_both_permissions_are_present(): void
    {
        $tenant = $this->createTenant();
        $this->createUserRole();
        $manager = $this->createTenantUser($tenant, 'full-permission-manager@example.com', [
            'update_user_info',
            'assign_permission',
        ]);
        $target = $this->createTenantUser($tenant, 'full-permission-target@example.com', []);
        app(TenantContext::class)->set($tenant);
        Auth::guard('tenantuser')->login($manager);

        app(TenantUserPermissionService::class)->authorizePermissionAssignment($target);

        $this->addToAssertionCount(1);
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

    protected function createTenantUserWithRole(Tenant $tenant, TenantRole $role, string $email): TenantUser
    {
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
