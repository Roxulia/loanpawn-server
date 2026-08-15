<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\PlatformUserUpsert;
use App\Exceptions\AccountNotFound;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\LanguageCodeInvalid;
use App\Exceptions\RequiredValueMissing;
use App\Models\PlatformModule\PlatformUser;
use App\Repository\PlatformUserRepository;
use App\Services\TableIdGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Collection;

class PlatformUserService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private PlatformUserRepository $repository,
        private TableIdGenerationService $tableIdGenerationService,
    )
    {
        //
    }

    public function findById($id) : PlatformUser
    {
        $res = $this->repository->findById($id);
        if(!$res)
        {
            throw new AccountNotFound(null);
        }
        return $res;
    }

    public function paginateAll(): LengthAwarePaginator
    {
        return $this->repository->paginateAll();
    }

    public function activeOptions(): Collection
    {
        return $this->repository->activeOptions();
    }

    public function create(PlatformUserUpsert $request): PlatformUser
    {
        return DB::transaction(fn () => $this->repository->create([
            'code' => $this->tableIdGenerationService->generateForPlatform('platform_users', CarbonImmutable::now()),
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($this->defaultPlatformUserPassword()),
            'status' => $request->status,
            'email_verified_at' => now(),
            'update_key' => $request->updateKey
        ]));
    }

    public function update(int $id, PlatformUserUpsert $request): PlatformUser
    {
        $platformUser = $this->findById($id);
        if($platformUser->update_key !== $request->updateKey)
        {
            throw new  AlreadyUpdatedException("This User is already updated. Please Refresh");
        }
        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'status' => $request->status,
            'update_key' => $request->updateKey
        ];

        return $this->repository->update($platformUser, $data);
    }

    public function resetPassword(int $id): PlatformUser
    {
        return $this->repository->update($this->findById($id), [
            'password' => Hash::make($this->defaultPlatformUserPassword()),
        ]);
    }

    public function changePreferLanguageForCurrentUser(PlatformUser $user, string $preferLang): PlatformUser
    {
        if (! in_array($preferLang, config('app.supported_locales', []), true)) {
            throw new LanguageCodeInvalid();
        }

        $updatedUser = $this->repository->update($user, [
            'prefer_lang' => $preferLang,
        ]);

        app()->setLocale($preferLang);

        if (request()->hasSession()) {
            session()->put('locale', $preferLang);
        }

        return $updatedUser;
    }

    public function delete(int $id): void
    {
        $this->repository->delete($this->findById($id));
    }

    private function defaultPlatformUserPassword(): string
    {
        $password = config('app.default_platform_user_password');

        if (! is_string($password) || trim($password) === '') {
            throw new RequiredValueMissing(null);
        }

        return $password;
    }
}
