<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantUserCreate;
use App\DataObjects\RequestObjects\TenantUserUpdate;
use App\DataObjects\ResponseObjects\TenantUserDetail;
use App\DataObjects\ResponseObjects\TenantUserListPage;
use App\DataObjects\ResponseObjects\TenantUserCreateResponse;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\DuplicateValueFound;
use App\Exceptions\InvalidCredential;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\LanguageCodeInvalid;
use App\Exceptions\TenantAccessDenied;
use App\Exceptions\TenantUserNotFound;
use App\Exceptions\UserNotLoggedIn;
use App\Models\CoreModule\TenantUser;
use App\Repository\TenantUserRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TableIdGenerationService;
use App\Support\TenantContext;
use App\Support\TenantScopedCacheKeys;
use App\Utility\MessageCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Utility\Messages;
class TenantUserService extends BaseTenantService
{
    protected const TENANT_USER_LIST_CACHE_TTL_SECONDS = 600;

    public function __construct(
        private TenantUserRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TenantRoleService $tenantRoleService,
        private TenantScopedCacheKeys $tenantScopedCacheKeys,
        private TenantSettingService $tenantSettingService,
        private TableIdGenerationService $tableIdGenerationService,
        private TenantLicenseService $tenantLicenseService,
        private Messages $messages
    ) {
    }

    public function list(int $perPage = 15): TenantUserListPage
    {
        $this->permissionService->authorizeUserList();
        $page = $this->resolveCurrentPage();
        $version = $this->tenantScopedCacheKeys->currentVersion('tenant-user-list');

        return Cache::remember(
            $this->tenantScopedCacheKeys->paginatedListKey('tenant-user-list', $version, $page, $perPage),
            now()->addSeconds(self::TENANT_USER_LIST_CACHE_TTL_SECONDS),
            fn () => TenantUserListPage::fromPaginator(
                $this->repository->paginate($perPage)
            )
        );
    }

    public function createForCurrentTenant(TenantUserCreate $request): TenantUserCreateResponse
    {
        $this->permissionService->authorizeUserCreate();
        $request->tenantId = $this->resolveCurrentTenantId();

        return $this->create($request);
    }

    public function show(int $tenantUserId): TenantUserDetail
    {
        $this->permissionService->authorizeUserList();

        return TenantUserDetail::fromModel($this->findUserForCurrentTenant($tenantUserId)->loadMissing(['role', 'permission']));
    }

    public function showByCode(string $code): TenantUserDetail
    {
        $this->permissionService->authorizeUserList();

        return TenantUserDetail::fromModel($this->findUserForCurrentTenantByCode($code)->loadMissing(['role', 'permission']));
    }

    public function create(TenantUserCreate $request): TenantUserCreateResponse
    {
        $tenantId = $request->tenantId ?? $this->resolveCurrentTenantId();

        if ($this->tenantLicenseService->checkIfLimitReach('current_staff_count', $tenantId)) {
            throw new TenantAccessDenied('Limit Reached');
        }
        $username = $this->generateUsername($tenantId, $request->name, $request->phone);
        $request->roleId = $request->roleId ?? $this->tenantRoleService->resolveDefaultRoleIdByName('User');
        $this->tenantRoleService->ensureRoleExists($request->roleId, $tenantId);
        $this->ensureUniqueFields($tenantId, $request, $username);
        $defaultPassword = $this->resolveTenantDefaultPassword($tenantId);
        $user = DB::transaction(function () use ($request, $tenantId, $username,$defaultPassword) {
            $data = [
                'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'tenant_users', CarbonImmutable::now()),
                'role_id' => $request->roleId,
                'username' => $username,
                'name' => $request->name,
                'nrc' => $request->nrc,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'password' => Hash::make($defaultPassword),
                'status' => $request->status,
                'is_deleted' => false,
                'created_by' => $request->createdBy,
            ];

            if ($request->tenantId !== null && app(TenantContext::class)->id() === null) {
                $data['tenant_id'] = $request->tenantId;
            }

            $user = $this->repository->create($data);
            $this->permissionService->createPermissionFromRole($user);
            $this->tenantLicenseService->incrementStaffCount($tenantId);
            return $user->refresh()->load(['role', 'permission']);
        });

        $this->flushTenantUserListCache($tenantId);

        return TenantUserCreateResponse::fromModel($user,$defaultPassword);
    }

    public function createAdmin(TenantUserCreate $request): TenantUserCreateResponse
    {
        $adminRole = $this->tenantRoleService->resolveDefaultRoleIdByName('Admin');

        return $this->create(new TenantUserCreate(
            tenantId: $request->tenantId,
            name: $request->name,
            nrc: $request->nrc,
            phone: $request->phone,
            email: $request->email,
            address: $request->address,
            roleId: $adminRole,
            status: $request->status,
            createdBy: $request->createdBy,
            password: null
        ));
    }

    public function createOwner(TenantUserCreate $request): TenantUserDetail
    {
        $ownerRole = $this->tenantRoleService->resolveDefaultRoleIdByName('Owner');
        $tenantId = $request->tenantId ?? $this->resolveCurrentTenantId();
        $username = $this->generateUsername($tenantId, $request->name, $request->phone);
        $this->tenantRoleService->ensureRoleExists($ownerRole, $tenantId);
        $this->ensureUniqueFields($tenantId, $request, $username);
        $user = DB::transaction(function () use ($request, $tenantId, $ownerRole, $username) {
            $data = [
                'code' => $this->tableIdGenerationService->generateForTenant($tenantId, 'tenant_users', CarbonImmutable::now()),
                'role_id' => $ownerRole,
                'username' => $username,
                'name' => $request->name,
                'nrc' => $request->nrc,
                'email' => $request->email,
                'phone' => $request->phone,
                'address' => $request->address,
                'password' => $request->password,
                'status' => $request->status,
                'is_deleted' => false,
                'created_by' => $request->createdBy,
            ];

            if ($request->tenantId !== null && app(TenantContext::class)->id() === null) {
                $data['tenant_id'] = $request->tenantId;
            }

            $user = $this->repository->create($data);
            $this->permissionService->createPermissionFromRole($user);

            return $user->refresh()->load(['role', 'permission']);
        });

        $this->flushTenantUserListCache($tenantId);

        return TenantUserDetail::fromModel($user);
    }

    public function changePreferLanguageForCurrentUser(TenantUserDetail $user, string $lang, int $updateKey): TenantUserDetail
    {
        if (! in_array($lang, config('app.supported_locales', []), true)) {
            //Log::error("Invalid language code provided for changing preferred language", ['lang' => $lang, 'user_id' => $user->id]);
            throw new LanguageCodeInvalid();
        }

        $updatedUser = DB::transaction(function () use ($user, $lang, $updateKey): TenantUser {
            $currentUser = $this->repository->findByIdWithLock($user->id);

            if (! $currentUser) {
                //Log::error("Current user not found during preferred language change", ['user_id' => $user->id]);
                throw new TenantUserNotFound();
            }

            if ((int) $currentUser->update_key !== $updateKey) {
                //Log::warning("Attempt to change preferred language with stale update key", ['user_id' => $user->id, 'provided_update_key' => $updateKey, 'current_update_key' => $currentUser->update_key]);
                throw new AlreadyUpdatedException('This User is already updated.Please refresh');
            }

            return $this->repository->update($currentUser, [
                'prefer_lang' => $lang,
                'update_key' => $currentUser->update_key + 1,
            ]);
        });

        $this->flushTenantUserListCache($updatedUser->tenant_id);
        app()->setLocale($lang);

        if (request()->hasSession()) {
            session()->put('locale', $lang);
        }

        return TenantUserDetail::fromModel($updatedUser);
    }

    public function update(TenantUserUpdate $request): TenantUserDetail
    {
        $targetUser = $this->findUserForCurrentTenant($request->userId);
        $canManageAll = $this->permissionService->resolveUpdateScope($targetUser, $request);

        if($targetUser->update_key !== $request->updateKey)
        {
            throw new AlreadyUpdatedException("This User is already updated.Please refresh");
        }

        $data = $this->buildUpdateData($targetUser, $request, $canManageAll);

        if ($data === []) {
            return TenantUserDetail::fromModel($targetUser->loadMissing(['role', 'permission']));
        }
        $data['update_key'] = $targetUser->update_key+1;

        $updatedUser = DB::transaction(fn () => $this->repository->updateWithLock($targetUser, $data));
        $this->flushTenantUserListCache();

        return TenantUserDetail::fromModel($updatedUser);
    }

    public function changeCurrentUserPassword(string $currentPassword, string $newPassword): void
    {
        $currentUser = Auth::guard('tenantuser')->user();

        if (! $currentUser instanceof TenantUser) {
            throw new UserNotLoggedIn(null);
        }

        $targetUser = $this->repository->findById($currentUser->id);

        if (! $targetUser || ! Hash::check($currentPassword, $targetUser->password)) {
            throw new InvalidCredential(null);
        }

        DB::transaction(function () use ($targetUser, $newPassword): void {
            $lockedUser = $this->repository->findByIdWithLock($targetUser->id);

            if (! $lockedUser) {
                throw new TenantUserNotFound();
            }

            $this->repository->update($lockedUser, [
                'password' => Hash::make($newPassword),
                'update_key' => $lockedUser->update_key+1
            ]);
        });

        $this->logoutUserFromAllDevices($targetUser);
        $this->flushTenantUserListCache($targetUser->tenant_id);
    }

    public function resetPasswordToDefault(int $tenantUserId, bool $logoutFromAll = false): void
    {
        $this->permissionService->authorizeUserUpdate();
        $targetUser = $this->findUserForCurrentTenant($tenantUserId);
        $defaultPassword = $this->resolveTenantDefaultPassword($targetUser->tenant_id);

        DB::transaction(function () use ($targetUser, $defaultPassword): void {
            $lockedUser = $this->repository->findByIdWithLock($targetUser->id);

            if (! $lockedUser) {
                throw new TenantUserNotFound();
            }

            $this->repository->update($lockedUser, [
                'password' => Hash::make($defaultPassword),
            ]);
        });

        if ($logoutFromAll) {
            $this->logoutUserFromAllDevices($targetUser);
        }

        $this->flushTenantUserListCache($targetUser->tenant_id);
    }

    public function updatePermissions(int $tenantUserId, array $permissions): TenantUserDetail
    {
        $targetUser = $this->findUserForCurrentTenant($tenantUserId);

        DB::transaction(function () use ($targetUser, $permissions): void {
            $lockedUser = $this->repository->findByIdWithLock($targetUser->id);

            if (! $lockedUser) {
                throw new TenantUserNotFound();
            }

            $this->permissionService->updateUserPermissions($lockedUser, $permissions);
        });

        $this->flushTenantUserListCache();

        return TenantUserDetail::fromModel($targetUser->refresh()->load(['role', 'permission']));
    }

    public function delete(int $userId): void
    {
        $this->permissionService->authorizeUserDelete();
        $targetUser = $this->findUserForCurrentTenant($userId);
        $ownerRole = $this->tenantRoleService->resolveDefaultRoleIdByName('Owner');
        if($targetUser->role_id === $ownerRole)
        {
            throw new InvalidTenantRequest($this->messages->responseMessage(MessageCode::DeleteOwner));
        }
        DB::transaction(function () use ($targetUser): void {
            $lockedUser = $this->repository->findByIdWithLock($targetUser->id);

            if (! $lockedUser) {
                throw new TenantUserNotFound();
            }

            $this->repository->update($lockedUser, [
                'is_deleted' => true,
                'status' => 'inactive',
                'update_key' => $lockedUser->update_key+1
            ]);
            $this->tenantLicenseService->decrementStaffCount($this->resolveCurrentTenantId());
        });

        $this->flushTenantUserListCache();
    }

    public function resolveIdByCode(string $code): int
    {
        if (ctype_digit($code)) {
            return $this->findUserForCurrentTenant((int) $code)->id;
        }

        return $this->findUserForCurrentTenantByCode($code)->id;
    }

    protected function ensureUniqueFields(int $tenantId, TenantUserCreate $request, string $username): void
    {
        if ($this->repository->existsForTenant($tenantId, 'username', $username)) {
            throw new DuplicateValueFound('Tenant username already exists.');
        }

        if ($request->email !== null && $this->repository->existsForTenant($tenantId, 'email', $request->email)) {
            throw new DuplicateValueFound('Tenant email already exists.');
        }

        if ($request->phone !== null && $this->repository->existsForTenant($tenantId, 'phone', $request->phone)) {
            throw new DuplicateValueFound('Tenant phone already exists.');
        }

        if ($this->repository->existsForTenant($tenantId, 'nrc', $request->nrc)) {
            throw new DuplicateValueFound('Tenant NRC already exists.');
        }
    }

    public function generateUsername(int $tenantId, string $name, string $phone): string
    {
        $initials = collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter(fn (?string $word) => $word !== null && $word !== '')
            ->map(function (string $word): string {
                $firstCharacter = mb_substr($word, 0, 1);

                return Str::upper($firstCharacter);
            })
            ->implode('');

        if ($initials === '') {
            $initials = 'U';
        }

        $initials = substr($initials, 0, 8);
        $reversedPhoneDigits = strrev(preg_replace('/\D/', '', $phone) ?? '');
        $baseLength = min(strlen($initials), 8);
        $digitsNeeded = 8 - $baseLength;
        $digitPart = substr($reversedPhoneDigits, 0, $digitsNeeded);

        if (strlen($digitPart) < $digitsNeeded) {
            $digitPart = str_pad($digitPart, $digitsNeeded, '0');
        }

        $baseUsername = substr($initials.$digitPart, 0, 8);
        $username = $baseUsername;
        $suffix = 1;

        while (TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('username', $username)
            ->exists()) {
            $suffixString = (string) $suffix;
            $prefixLength = 8 - strlen($suffixString);
            $username = substr($baseUsername, 0, max(0, $prefixLength)).$suffixString;
            $suffix++;
        }

        return $username;
    }

    protected function buildUpdateData(TenantUser $targetUser, TenantUserUpdate $request, bool $canManageAll): array
    {
        $data = [];

        if ($request->name !== null && $request->name !== $targetUser->name) {
            $data['name'] = $request->name;
        }

        if ($request->nrc !== null && $request->nrc !== $targetUser->nrc) {
            if ($this->repository->existsByField('nrc', $request->nrc, $targetUser->id)) {
                throw new DuplicateValueFound('Tenant NRC already exists.');
            }
            $data['nrc'] = $request->nrc;
        }

        if ($request->email !== null && $request->email !== $targetUser->email) {
            if ($this->repository->existsByField('email', $request->email, $targetUser->id)) {
                throw new DuplicateValueFound('Tenant email already exists.');
            }
            $data['email'] = $request->email;
        }

        if ($request->phone !== null && $request->phone !== $targetUser->phone) {
            if ($this->repository->existsByField('phone', $request->phone, $targetUser->id)) {
                throw new DuplicateValueFound('Tenant phone already exists.');
            }
            $data['phone'] = $request->phone;
        }

        if ($request->address !== null && $request->address !== $targetUser->address) {
            $data['address'] = $request->address;
        }

        if ($canManageAll && $request->roleId !== null && $request->roleId !== $targetUser->role_id) {
            $this->tenantRoleService->ensureRoleExists($request->roleId, $targetUser->tenant_id);
            $data['role_id'] = $request->roleId;
        }

        if ($canManageAll && $request->status !== null) {
            $data['status'] = $request->status;
        }

        return $data;
    }

    protected function findUserForCurrentTenant(int $userId): TenantUser
    {
        $user = $this->repository->findById($userId);

        if (! $user) {
            throw new TenantUserNotFound();
        }

        return $user;
    }

    protected function findUserForCurrentTenantByCode(string $code): TenantUser
    {
        $user = $this->repository->findByCode($code);

        if (! $user) {
            throw new TenantUserNotFound();
        }

        return $user;
    }

    protected function flushTenantUserListCache(?int $tenantId = null): void
    {
        $this->tenantScopedCacheKeys->bumpVersion('tenant-user-list', tenantId: $tenantId);
    }

    protected function resolveCurrentPage(): int
    {
        return max(1, (int) request()->query('page', 1));
    }

    protected function logoutUserFromAllDevices(TenantUser $user): void
    {
        $user->tokens()->delete();
        $this->repository->deleteSessionsForUser($user->id);
    }

    protected function resolveTenantDefaultPassword(int $tenantId): string
    {
        return $this->tenantSettingService->getTenantDefaultUserPassword($tenantId) ?? '12345678';
    }

}
