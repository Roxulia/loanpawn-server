<?php

namespace App\Repository;

use App\Models\PlatformModule\PlatformUser;
use App\Exceptions\RequiredValueMissing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PlatformUserRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function findByEmail(string $email) : ?PlatformUser
    {
        $res = PlatformUser::query()->where('email',$email)->first();
        return $res;
    }

    public function findById(int $id) : ?PlatformUser
    {
        $res = PlatformUser::query()->find($id);
        return $res;
    }

    public function paginateAll(int $perPage = 15): LengthAwarePaginator
    {
        return PlatformUser::query()
            ->withCount(['tenants', 'tenantRequests'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function activeOptions(): Collection
    {
        return PlatformUser::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'email']);
    }

    public function create(array $data): PlatformUser
    {
        $this->requireValue($data, 'code');

        return PlatformUser::query()->create($data);
    }

    protected function requireValue(array $data, string $key): void
    {
        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
            throw new RequiredValueMissing("Platform user {$key} is required.");
        }
    }

    public function update(PlatformUser $platformUser, array $data): PlatformUser
    {
        $platformUser->update($data);

        return $platformUser->refresh();
    }

    public function delete(PlatformUser $platformUser): void
    {
        $platformUser->delete();
    }
}
