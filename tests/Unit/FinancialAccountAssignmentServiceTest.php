<?php

namespace Tests\Unit;

use App\DataObjects\RequestObjects\FinancialAccountAssignmentUpdate;
use App\Exceptions\FinancialAccountAccessDenied;
use App\Exceptions\FinancialAccountAssignmentDenied;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\FinancialAccount;
use App\Repository\Accounting\FinancialAccountAssignmentRepository;
use App\Services\TenantModule\Accounting\FinancialAccountAssignmentService;
use App\Services\TenantModule\TenantUserPermissionService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

class FinancialAccountAssignmentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(TenantContext::class)->set(41);
    }

    public function test_user_cannot_change_own_assignments(): void
    {
        $user = $this->user(7, 'USER-7', 'User');
        $repository = Mockery::mock(FinancialAccountAssignmentRepository::class);
        $repository->shouldReceive('findUserByCode')->once()->with(41, 'USER-7')->andReturn($user);
        $repository->shouldNotReceive('syncForUser');
        $permissionService = $this->permissionService();
        Auth::shouldReceive('guard')->with('tenantuser')->andReturn($this->guardWithUser($user));

        $this->expectException(FinancialAccountAssignmentDenied::class);
        $this->service($repository, $permissionService)->updateForUser('USER-7', new FinancialAccountAssignmentUpdate([91]));
    }

    public function test_owner_assignments_cannot_be_changed(): void
    {
        $owner = $this->user(8, 'OWNER-8', 'Owner');
        $repository = Mockery::mock(FinancialAccountAssignmentRepository::class);
        $repository->shouldReceive('findUserByCode')->once()->with(41, 'OWNER-8')->andReturn($owner);
        $repository->shouldNotReceive('syncForUser');
        $permissionService = $this->permissionService();
        Auth::shouldReceive('guard')->with('tenantuser')->andReturn($this->guardWithUser($this->user(7, 'ADMIN-7', 'Admin')));

        $this->expectException(FinancialAccountAssignmentDenied::class);
        $this->service($repository, $permissionService)->updateForUser('OWNER-8', new FinancialAccountAssignmentUpdate([]));
    }

    public function test_operational_access_requires_an_assignment(): void
    {
        $user = $this->user(7, 'USER-7', 'User');
        $account = new FinancialAccount;
        $account->forceFill(['id' => 91, 'tenant_id' => 41]);
        $repository = Mockery::mock(FinancialAccountAssignmentRepository::class);
        $repository->shouldReceive('isAssigned')->once()->with(41, 91, 7)->andReturnFalse();
        Auth::shouldReceive('guard')->with('tenantuser')->andReturn($this->guardWithUser($user));

        $this->expectException(FinancialAccountAccessDenied::class);
        $this->service($repository, Mockery::mock(TenantUserPermissionService::class))->assertCurrentUserAssigned($account);
    }

    public function test_new_account_assignment_targets_every_owner_idempotently(): void
    {
        $account = new FinancialAccount;
        $account->forceFill(['id' => 91, 'tenant_id' => 41]);
        $repository = Mockery::mock(FinancialAccountAssignmentRepository::class);
        $repository->shouldReceive('ownerUsers')->once()->with(41)->andReturn(new Collection([
            $this->user(8, 'OWNER-8', 'Owner'),
        ]));
        $repository->shouldReceive('assignUsersToAccount')->once()->with(41, 91, [8])->andReturn(1);

        $this->assertSame(1, $this->service($repository, Mockery::mock(TenantUserPermissionService::class))->assignOwnersToAccount($account));
    }

    private function permissionService(): TenantUserPermissionService
    {
        $service = Mockery::mock(TenantUserPermissionService::class);
        $service->shouldReceive('authorizePermission')->once()->with('manage_financial_account_assignments');
        return $service;
    }

    private function service(FinancialAccountAssignmentRepository $repository, TenantUserPermissionService $permissionService): FinancialAccountAssignmentService
    {
        return new FinancialAccountAssignmentService($repository, $permissionService);
    }

    private function user(int $id, string $code, string $roleName): TenantUser
    {
        $user = new TenantUser;
        $user->forceFill(['id' => $id, 'tenant_id' => 41, 'code' => $code, 'status' => 'active']);
        $role = new TenantRole;
        $role->forceFill(['name' => $roleName]);
        $user->setRelation('role', $role);
        return $user;
    }

    private function guardWithUser(TenantUser $user): object
    {
        $guard = Mockery::mock();
        $guard->shouldReceive('user')->andReturn($user);
        return $guard;
    }
}
