<?php

namespace Tests\Feature\PlatformModule;

use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Services\PlatformModule\AuthService;
use App\Services\PlatformModule\OwnedTenantUserCredentialSyncService;
use App\Services\PlatformModule\PlatformUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PlatformOwnedTenantPasswordSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_user_change_password_synchronizes_only_email_matches_in_owned_tenants_and_revokes_access(): void
    {
        config(['session.driver' => 'database']);
        $owner = $this->platformUser('owner@example.com', 'old-password');
        $otherOwner = $this->platformUser('other@example.com', 'other-password');
        $firstTenant = $this->tenant($owner, 'first');
        $secondTenant = $this->tenant($owner, 'second');
        $otherTenant = $this->tenant($otherOwner, 'other');
        $firstMatch = $this->tenantUser($firstTenant, $owner->email, 'first', updateKey: 3);
        $secondMatch = $this->tenantUser($secondTenant, $owner->email, 'second', status: 'inactive', updateKey: 7);
        $differentEmail = $this->tenantUser($firstTenant, 'different@example.com', 'different');
        $deletedMatch = $this->tenantUser($secondTenant, $owner->email, 'deleted', isDeleted: true);
        $unownedMatch = $this->tenantUser($otherTenant, $owner->email, 'unowned');
        $firstMatch->createToken('first-session');
        $secondMatch->createToken('second-session');
        $tenantGuardKey = Auth::guard('tenantuser')->getName();
        $platformGuardKey = Auth::guard('platformuser')->getName();
        $this->insertSession('tenant-session', $firstMatch->id, [$tenantGuardKey => $firstMatch->id]);
        $this->insertSession('platform-session', $firstMatch->id, [$platformGuardKey => $firstMatch->id]);

        $response = $this->actingAs($owner, 'platformuser')->putJson(route('platform.password.change'), [
            'current_password' => 'old-password',
            'password' => 'NewPassword@123',
            'password_confirmation' => 'NewPassword@123',
        ]);

        $response->assertOk();
        $owner->refresh();
        $firstMatch->refresh();
        $secondMatch->refresh();
        $this->assertTrue(Hash::check('NewPassword@123', $owner->password));
        $this->assertSame(1, $owner->update_key);
        $this->assertNull($owner->remember_token);
        $this->assertSame($owner->password, $firstMatch->password);
        $this->assertSame($owner->password, $secondMatch->password);
        $this->assertSame(4, $firstMatch->update_key);
        $this->assertSame(8, $secondMatch->update_key);
        $this->assertNull($firstMatch->remember_token);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $firstMatch->id, 'tokenable_type' => TenantUser::class]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $secondMatch->id, 'tokenable_type' => TenantUser::class]);
        $this->assertDatabaseMissing('sessions', ['id' => 'tenant-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'platform-session']);
        $this->assertTrue(Hash::check('tenant-password', $differentEmail->refresh()->password));
        $this->assertTrue(Hash::check('tenant-password', $deletedMatch->refresh()->password));
        $this->assertTrue(Hash::check('tenant-password', $unownedMatch->refresh()->password));
    }

    public function test_otp_reset_synchronizes_password_and_consumes_reset_token(): void
    {
        $owner = $this->platformUser('reset@example.com', 'old-password');
        $match = $this->tenantUser($this->tenant($owner, 'reset'), $owner->email, 'reset');
        DB::table('platform_user_password_reset_tokens')->insert([
            'email' => $owner->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);

        app(AuthService::class)->resetPassword($owner->email, 'ResetPassword@123', false);

        $this->assertSame($owner->refresh()->password, $match->refresh()->password);
        $this->assertTrue(Hash::check('ResetPassword@123', $owner->password));
        $this->assertDatabaseMissing('platform_user_password_reset_tokens', ['email' => $owner->email]);
    }

    public function test_admin_reset_to_default_password_synchronizes_owned_tenant_users(): void
    {
        config(['app.default_platform_user_password' => 'DefaultPassword@123']);
        $owner = $this->platformUser('admin-reset@example.com', 'old-password');
        $match = $this->tenantUser($this->tenant($owner, 'admin-reset'), $owner->email, 'admin-reset');

        app(PlatformUserService::class)->resetPassword($owner->id);

        $this->assertSame($owner->refresh()->password, $match->refresh()->password);
        $this->assertTrue(Hash::check('DefaultPassword@123', $owner->password));
    }

    public function test_sync_failure_rolls_back_platform_and_tenant_password_changes(): void
    {
        $owner = $this->platformUser('rollback@example.com', 'old-password');
        $match = $this->tenantUser($this->tenant($owner, 'rollback'), $owner->email, 'rollback');
        $oldPlatformHash = $owner->password;
        $oldTenantHash = $match->password;
        DB::table('platform_user_password_reset_tokens')->insert([
            'email' => $owner->email,
            'token' => Hash::make('123456'),
            'created_at' => now(),
        ]);
        $sync = Mockery::mock(OwnedTenantUserCredentialSyncService::class);
        $sync->shouldReceive('synchronize')->once()->andReturnUsing(function () use ($match): never {
            $match->forceFill(['password' => Hash::make('partial-password')])->save();
            throw new RuntimeException('Synchronization failed.');
        });
        $this->app->instance(OwnedTenantUserCredentialSyncService::class, $sync);

        try {
            app(AuthService::class)->resetPassword($owner->email, 'NewPassword@123', false);
            $this->fail('Expected synchronization failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synchronization failed.', $exception->getMessage());
        }

        $this->assertSame($oldPlatformHash, $owner->refresh()->password);
        $this->assertSame($oldTenantHash, $match->refresh()->password);
        $this->assertDatabaseHas('platform_user_password_reset_tokens', ['email' => $owner->email]);
    }

    public function test_platform_admin_password_change_does_not_touch_tenant_users(): void
    {
        $owner = $this->platformUser('owner-for-admin@example.com', 'owner-password');
        $match = $this->tenantUser($this->tenant($owner, 'admin-isolation'), $owner->email, 'admin-isolation');
        $oldTenantHash = $match->password;
        $admin = PlatformAdmin::query()->create([
            'code' => 'PA00000001',
            'name' => 'Platform Admin',
            'username' => 'admin-user',
            'email' => 'admin@example.com',
            'password' => 'admin-password',
            'status' => 'active',
        ]);
        Auth::guard('platformadmin')->login($admin);

        app(AuthService::class)->changePassword('admin-password', 'AdminPassword@123', true);

        $this->assertTrue(Hash::check('AdminPassword@123', $admin->refresh()->password));
        $this->assertSame($oldTenantHash, $match->refresh()->password);
    }

    private function platformUser(string $email, string $password): PlatformUser
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.strtoupper(substr(hash('sha256', $email), 0, 8)),
            'name' => 'Platform Owner',
            'email' => $email,
            'password' => $password,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $platformUser->forceFill(['remember_token' => 'remember-platform'])->save();

        return $platformUser->refresh();
    }

    private function tenant(PlatformUser $owner, string $suffix): Tenant
    {
        return Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => ucfirst($suffix).' Tenant',
            'tenant_code' => 'tenant-'.$suffix,
            'subdomain' => $suffix,
            'status' => 'active',
        ]);
    }

    private function tenantUser(
        Tenant $tenant,
        string $email,
        string $suffix,
        string $status = 'active',
        bool $isDeleted = false,
        int $updateKey = 0,
    ): TenantUser {
        $tenantUser = TenantUser::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'code' => 'TU'.strtoupper(substr(hash('sha256', $tenant->id.$suffix), 0, 8)),
            'username' => substr('u'.$suffix, 0, 8),
            'name' => ucfirst($suffix).' User',
            'nrc' => 'NRC-'.$suffix,
            'email' => $email,
            'password' => 'tenant-password',
            'status' => $status,
            'is_deleted' => $isDeleted,
            'update_key' => $updateKey,
        ]);

        $tenantUser->forceFill(['remember_token' => 'remember-me'])->save();

        return $tenantUser->refresh();
    }

    private function insertSession(string $id, int $userId, array $attributes): void
    {
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => base64_encode(serialize($attributes)),
            'last_activity' => now()->timestamp,
        ]);
    }
}
