<?php

namespace App\Services\PlatformModule\TenantServices;

use App\DataObjects\RequestObjects\TenantContactUpdate;
use App\DataObjects\RequestObjects\TenantCreate;
use App\DataObjects\RequestObjects\TenantUpdate;
use App\DataObjects\ResponseObjects\TenantContactDetail;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantContact;
use App\Models\PlatformModule\Tenant;
use App\Repository\TenantContactRepository;
use App\Services\BaseTenantService;

class TenantContactService extends BaseTenantService
{
    public function __construct(
        private TenantContactRepository $repository,
    ) {
    }

    public function createContact(TenantCreate $request, int $tenantId): TenantContact
    {
        return $this->repository->create([
            'tenant_id' => $tenantId,
            'tenant_code' => $this->resolveTenantCode($tenantId),
            'address' => $request->address,
            'phone' => $request->phone,
            'city' => $request->city,
            'country' => $request->country,
        ]);
    }

    public function findByTenantId(int $tenantId): TenantContactDetail
    {
        $contact = $this->repository->findByTenantId($tenantId);

        if ($contact == null) {
            throw new TenantNotFound('Tenant contact not found.');
        }

        return TenantContactDetail::fromModel($contact);
    }

    public function upsertContact(TenantUpdate $request, int $tenantId): TenantContact
    {
        $data = [];

        if ($request->address !== null) {
            $data['address'] = $request->address;
        }

        if ($request->phone !== null) {
            $data['phone'] = $request->phone;
        }

        if ($request->city !== null) {
            $data['city'] = $request->city;
        }

        if ($request->country !== null) {
            $data['country'] = $request->country;
        }

        $contact = $this->repository->findByTenantId($tenantId);

        if ($contact == null) {
            return $this->repository->create([
                'tenant_id' => $tenantId,
                'tenant_code' => $this->resolveTenantCode($tenantId),
                ...$data,
            ]);
        }

        if ($data === []) {
            return $contact;
        }

        return $this->repository->update($contact, $data);
    }

    public function updateCurrentTenantContact(TenantContactUpdate $request): TenantContactDetail
    {
        $tenantId = $this->resolveCurrentTenantId();
        $contact = $this->repository->firstOrCreateForTenant(
            $tenantId,
            [
                'tenant_code' => $this->resolveCurrentTenantCode(),
            ],
        );

        if ((int) $contact->update_key !== $request->updateKey) {
            throw new AlreadyUpdatedException('This contact is already updated. Please refresh to see the update.');
        }

        $data = [
            'tenant_code' => $this->resolveCurrentTenantCode(),
            'update_key' => $contact->update_key + 1,
        ];

        if ($request->address !== null) {
            $data['address'] = $request->address;
        }

        if ($request->phone !== null) {
            $data['phone'] = $request->phone;
        }

        if ($request->city !== null) {
            $data['city'] = $request->city;
        }

        if ($request->country !== null) {
            $data['country'] = $request->country;
        }

        return TenantContactDetail::fromModel($this->repository->update($contact, $data));
    }

    public function getCurrentTenantContact(): TenantContactDetail
    {
        $tenantId = $this->resolveCurrentTenantId();

        return TenantContactDetail::fromModel($this->repository->firstOrCreateForTenant(
            $tenantId,
            [
                'tenant_code' => $this->resolveCurrentTenantCode(),
            ],
        ));
    }

    protected function resolveTenantCode(int $tenantId): string
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            throw new TenantNotFound('Tenant not found.');
        }

        return $tenant->tenant_code;
    }
}
