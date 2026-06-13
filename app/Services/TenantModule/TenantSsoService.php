<?php

namespace App\Services\TenantModule;

use App\DataObjects\ResponseObjects\TenantUserAuthSession;
use App\Exceptions\InvalidCredential;
use App\Exceptions\TenantAccessDenied;
use App\Exceptions\TenantNotFound;
use App\Exceptions\TenantUserNotFound;
use App\Repository\TenantRepository;
use App\Repository\TenantUserRepository;
use App\Services\PlatformModule\AuthService as PlatformAuthService;
use App\Services\PlatformModule\TenantServices\TenantLookupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TenantSsoService
{
    private const TOKEN_TTL_MINUTES = 5;

    public function __construct(
        private PlatformAuthService $platformAuthService,
        private TenantRepository $tenantRepository,
        private TenantUserRepository $tenantUserRepository,
        private TenantLookupService $tenantLookupService,
        private AuthService $tenantAuthService,
    ) {
    }

    public function createRedirectUrl(int $tenantId): string
    {
        $platformUser = $this->platformAuthService->getCurrentUser('platformuser');
        $tenant = $this->tenantRepository->findByIdForPlatformUser($tenantId, $platformUser->id);

        if (! $tenant) {
            throw new TenantNotFound(null);
        }

        $tenantUser = $this->tenantUserRepository->findByTenantIdAndEmail($tenant->id, $platformUser->email);

        if (! $tenantUser) {
            throw new TenantUserNotFound('Tenant owner user was not found.');
        }

        $token = Str::random(64);

        DB::table('tenant_sso_tokens')->insert([
            'tenant_id' => $tenant->id,
            'platform_user_id' => $platformUser->id,
            'tenant_user_id' => $tenantUser->id,
            'token_hash' => Hash::make($token),
            'expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return rtrim(config('services.tenant_app.url', 'https://app.loanpawn.1morebit.tech'), '/')
            .'/sso?tenantCode='.urlencode($tenant->tenant_code)
            .'&token='.urlencode($token);
    }

    public function consume(string $tenantCode, string $token): TenantUserAuthSession
    {
        $tenant = $this->tenantLookupService->findByTenantCode($tenantCode);

        return DB::transaction(function () use ($tenant, $token): TenantUserAuthSession {
            $rows = DB::table('tenant_sso_tokens')
                ->where('tenant_id', $tenant->id)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            foreach ($rows as $row) {
                if (! Hash::check($token, $row->token_hash)) {
                    continue;
                }

                DB::table('tenant_sso_tokens')
                    ->where('id', $row->id)
                    ->update([
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $this->tenantAuthService->loginFromSso((int) $row->tenant_id, (int) $row->tenant_user_id);
            }

            throw new InvalidCredential('Invalid or expired SSO token.');
        });
    }
}
