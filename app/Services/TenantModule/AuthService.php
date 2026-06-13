<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantUserPublicLogin;
use App\DataObjects\RequestObjects\TenantUserSubdomainLogin;
use App\DataObjects\ResponseObjects\TenantUserAuthSession;
use App\DataObjects\ResponseObjects\TenantUserDetail;
use App\Exceptions\EmailNotRegistered;
use App\Exceptions\InvalidCredential;
use App\Exceptions\LoginNotAllowed;
use App\Exceptions\UserNotLoggedIn;
use App\Models\CoreModule\TenantUser;
use App\Repository\TenantUserRepository;
use App\Services\AuthLoginAttemptService;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\TenantServices\TenantLookupService;
use App\Support\TenantContext;
use App\Support\TenantScopedCacheKeys;
use Illuminate\Contracts\Cookie\QueueingFactory as CookieFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthService extends BaseTenantService
{
    private const GUARD = 'tenantuser';

    private const TOKEN_COOKIE = 'tenant_auth_token';

    public function __construct(
        private TenantUserRepository $repository,
        private TenantLookupService $tenantLookupService,
        private CookieFactory $cookieFactory,
        private TenantScopedCacheKeys $cache,
        private AuthLoginAttemptService $loginAttemptService,
    ) {}

    public function loginFromPublicSpa(TenantUserPublicLogin $request): TenantUserAuthSession
    {
        $tenant = $this->tenantLookupService->findByTenantCode($request->tenantCode);
        app(TenantContext::class)->set($tenant);

        $session = $this->authenticateTenantUser(
            $tenant->id,
            $tenant->tenant_code,
            $request->email,
            $request->password
        );
        $this->cookieFactory->queue($this->makeAuthCookie($session));
        $this->applyUserLocale($session->user->preferLang ?? config('app.locale'));

        return $session;
    }

    public function loginFromSubdomainSpa(TenantUserSubdomainLogin $request): TenantUserAuthSession
    {
        $tenantId = $this->resolveCurrentTenantId();
        $tenant = $this->tenantLookupService->findById($tenantId);

        $session = $this->authenticateTenantUser(
            $tenantId,
            $tenant->tenant_code,
            $request->email,
            $request->password
        );
        $this->cookieFactory->queue($this->makeAuthCookie($session));
        $this->applyUserLocale($session->user->preferLang ?? config('app.locale'));

        return $session;
    }

    public function loginFromSso(int $tenantId, int $tenantUserId): TenantUserAuthSession
    {
        $tenant = $this->tenantLookupService->findById($tenantId);
        app(TenantContext::class)->set($tenant);

        $user = $this->repository->findById($tenantUserId);

        if (! $user || (int) $user->tenant_id !== $tenantId || $user->status !== 'active') {
            throw new InvalidCredential(null);
        }

        $this->logoutOtherGuards();
        Auth::shouldUse(self::GUARD);
        Auth::guard(self::GUARD)->login($user);

        $user = $this->repository->update($user, [
            'last_login_at' => now(),
            'status' => 'active',
        ])->loadMissing(['role', 'permission']);

        $session = TenantUserAuthSession::fromModel(
            $user,
            $tenant->tenant_code,
            self::TOKEN_COOKIE,
            json_encode([
                'tenantId' => $tenantId,
                'username' => $user->username,
                'email' => $user->email,
                'roleId' => $user->role_id,
                'tenantCode' => $tenant->tenant_code,
            ], JSON_THROW_ON_ERROR)
        );

        $this->cookieFactory->queue($this->makeAuthCookie($session));
        $this->applyUserLocale($user->prefer_lang ?? config('app.locale'));

        return $session;
    }

    public function getCurrentUser(): TenantUserDetail
    {
        $user = Auth::guard(self::GUARD)->user();

        if (! $user instanceof TenantUser) {
            throw new UserNotLoggedIn(null);
        }

        return TenantUserDetail::fromModel($user->loadMissing(['role', 'permission']));
    }

    public function logout(): void
    {
        $user = Auth::guard(self::GUARD)->user();
        $user = $this->repository->update($user, [
            'status' => 'inactive',
        ])->loadMissing(['role', 'permission']);
        $this->flushTenantUserListCache((int) $user->tenant_id);
        Auth::guard(self::GUARD)->logout();
        $this->cookieFactory->queue($this->forgetAuthCookie());
    }

    public function makeAuthCookie(TenantUserAuthSession $session): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie(
            $session->tokenName,
            $session->tokenValue,
            (int) config('session.lifetime', 120),
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax')
        );
    }

    public function forgetAuthCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie()->forget(
            self::TOKEN_COOKIE,
            '/',
            config('session.domain')
        );
    }

    protected function authenticateTenantUser(
        int $tenantId,
        string $tenantCode,
        string $email,
        string $password
    ): TenantUserAuthSession {
        $scope = 'tenant:'.$tenantId;
        $user = $this->repository->findByTenantIdAndEmail($tenantId, $email);

        if (! $user) {
            throw new EmailNotRegistered;
        }

        $this->loginAttemptService->ensureIsNotLocked(self::GUARD, $email, $scope);

        if (! Hash::check($password, $user->password)) {
            $this->loginAttemptService->recordFailedPassword(self::GUARD, $email, $scope);

            throw new InvalidCredential(null);
        }

        if (! in_array($user->status, ['active', 'inactive'], true)) {
            throw new LoginNotAllowed;
        }

        $this->loginAttemptService->clear(self::GUARD, $email, $scope);
        $this->logoutOtherGuards();
        Auth::shouldUse(self::GUARD);
        Auth::guard(self::GUARD)->login($user);

        $user = $this->repository->update($user, [
            'last_login_at' => now(),
            'status' => 'active',
        ])->loadMissing(['role', 'permission']);
        $this->flushTenantUserListCache($tenantId);

        return TenantUserAuthSession::fromModel(
            $user,
            $tenantCode,
            self::TOKEN_COOKIE,
            json_encode([
                'tenantId' => $tenantId,
                'username' => $user->username,
                'email' => $user->email,
                'roleId' => $user->role_id,
                'tenantCode' => $tenantCode,
            ], JSON_THROW_ON_ERROR)
        );
    }

    protected function logoutOtherGuards(): void
    {
        Auth::guard('web')->logout();
        Auth::guard('platformuser')->logout();
        Auth::guard('platformadmin')->logout();
    }

    protected function flushTenantUserListCache(int $tenantId): void
    {
        $this->cache->bumpVersion('tenant-user-list', tenantId: $tenantId);
    }

    protected function applyUserLocale(string $locale): void
    {
        if (! in_array($locale, config('app.supported_locales', []), true)) {
            $locale = config('app.locale');
        }

        app()->setLocale($locale);

        if (request()->hasSession()) {
            session()->put('locale', $locale);
        }
    }
}
