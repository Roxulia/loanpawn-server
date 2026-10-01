<?php

namespace App\Services\PlatformModule;

use App\Models\CatalogModule\CatalogUnit;
use App\Repository\CatalogTaxonomyRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogUnitAdministrationService
{
    public function __construct(private CatalogTaxonomyRepository $repository)
    {
    }

    public function list(): array
    {
        return $this->repository->platformUnits()->all();
    }

    public function create(array $data): CatalogUnit
    {
        $data['code'] = strtolower(trim($data['code']));

        if ($this->repository->findPlatformUnitByCode($data['code'])) {
            throw ValidationException::withMessages([
                'code' => ['This global unit code already exists.'],
            ]);
        }

        $data['created_by_platform_admin_id'] = Auth::guard('platformadmin')->id();

        return DB::transaction(function () use ($data): CatalogUnit {
            return $this->repository->createPlatformUnit($data);
        });
    }

    public function update(int $id, array $data): CatalogUnit
    {
        $unit = $this->repository->findPlatformUnit($id);
        abort_if($unit === null, 404);

        if ($unit->is_system && !$data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => ['The system default Unit cannot be deactivated.'],
            ]);
        }

        $changes = [
            'name' => trim($data['name']),
            'symbol' => $data['symbol'] ?: null,
            'is_active' => (bool) $data['is_active'],
        ];

        return DB::transaction(function () use ($unit, $changes): CatalogUnit {
            return $this->repository->updateUnit($unit, $changes);
        });
    }
}
