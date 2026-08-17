<?php

namespace App\Services\PlatformModule;

use App\Exceptions\AccountNotFound;
use App\Models\PlatformModule\PlatformUser;
use App\Repository\PlatformUserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PlatformUserCredentialService
{
    public function __construct(
        private PlatformUserRepository $repository,
        private OwnedTenantUserCredentialSyncService $tenantCredentialSync,
    ) {}

    public function replacePassword(int $platformUserId, string $newPassword): PlatformUser
    {
        $passwordHash = Hash::make($newPassword);

        return DB::transaction(function () use ($platformUserId, $passwordHash): PlatformUser {
            $platformUser = $this->repository->findByIdForUpdate($platformUserId)
                ?? throw new AccountNotFound(null);
            $platformUser = $this->repository->updatePasswordCredentials($platformUser, $passwordHash);

            $this->tenantCredentialSync->synchronize($platformUser, $passwordHash);

            return $platformUser;
        });
    }
}
