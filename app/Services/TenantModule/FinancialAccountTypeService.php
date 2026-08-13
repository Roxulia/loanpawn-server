<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\StoreFinancialAccountTypeRequest;
use App\DataObjects\RequestObjects\UpdateFinancialAccountTypeRequest;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\DataObjects\ResponseObjects\FinancialAccountTypeResource;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantAccessDenied;
use App\Models\FinancialAccountTypes;
use App\Repository\FinancialAccountTypeRepository;
use App\Services\BaseTenantService;
use App\Utility\MessageCode;
use Illuminate\Support\Str;

class FinancialAccountTypeService extends BaseTenantService
{
    public function __construct(private FinancialAccountTypeRepository $repository) {}

    public function list(int $perPage = 15): DefaultDataListPage
    {
        $page = $this->repository->paginateActiveVisibleToTenant($this->resolveCurrentTenantId(), $perPage);
        $page->through(fn (FinancialAccountTypes $type) => FinancialAccountTypeResource::fromModel($type)->toArray());

        return DefaultDataListPage::fromPaginator($page);
    }

    public function create(StoreFinancialAccountTypeRequest $request): FinancialAccountTypeResource
    {
        $tenantId = $this->resolveCurrentTenantId();
        $code = $this->normalizeCode($request->code);
        $existing = $this->repository->findOwnedByCode($tenantId, $code);

        if ($existing?->is_active) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceFinancialAccountTypeCodeAlreadyAvailable));
        }

        $type = $existing
            ? $this->repository->update($existing, ['name' => trim($request->name), 'is_active' => true, 'update_key' => $existing->update_key + 1])
            : $this->repository->create(['tenant_id' => $tenantId, 'code' => $code, 'name' => trim($request->name), 'is_active' => true]);

        return FinancialAccountTypeResource::fromModel($type);
    }

    public function update(string $currentCode, UpdateFinancialAccountTypeRequest $request): FinancialAccountTypeResource
    {
        $tenantId = $this->resolveCurrentTenantId();
        $type = $this->owned($tenantId, $this->normalizeCode($currentCode));
        if ($request->updateKey !== $type->update_key) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceFinancialAccountTypeAlreadyUpdated));
        }

        $nextCode = $this->normalizeCode($request->code);
        $collision = $this->repository->findOwnedByCode($tenantId, $nextCode);
        if ($collision && $collision->id !== $type->id) {
            throw new InvalidTenantRequest($this->responseMessage(MessageCode::FinanceFinancialAccountTypeCodeAlreadyAvailable));
        }

        return FinancialAccountTypeResource::fromModel($this->repository->update($type, [
            'code' => $nextCode,
            'name' => trim($request->name),
            'update_key' => $type->update_key + 1,
        ]));
    }

    public function delete(string $code): void
    {
        $type = $this->owned($this->resolveCurrentTenantId(), $this->normalizeCode($code));
        $this->repository->update($type, ['is_active' => false, 'update_key' => $type->update_key + 1]);
    }

    private function owned(int $tenantId, string $code): FinancialAccountTypes
    {
        $type = $this->repository->findOwnedByCode($tenantId, $code);

        if (! $type || ! $type->is_active) {
            throw new TenantAccessDenied($this->responseMessage(MessageCode::FinanceTenantFinancialAccountTypeModificationDenied));
        }

        return $type;
    }

    private function normalizeCode(string $code): string
    {
        return Str::slug(trim($code), '_');
    }
}
