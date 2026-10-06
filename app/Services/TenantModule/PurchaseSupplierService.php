<?php

namespace App\Services\TenantModule;

use App\Models\PurchasingModule\PurchaseSupplier;
use App\Repository\PurchasingRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PurchaseSupplierService extends BaseTenantService
{
    public function __construct(
        private PurchasingRepository $repository,
        private TenantIdempotencyService $idempotencyService,
        private TableIdGenerationService $codeGenerator,
    ) {}

    public function suppliers(?string $search = null): array
    {
        return $this->repository->suppliers($this->resolveCurrentTenantId(), $search)
            ->map(fn (PurchaseSupplier $supplier) => $this->resource($supplier))->all();
    }

    public function supplier(string $code): array
    {
        $supplier = $this->repository->supplier($this->resolveCurrentTenantId(), $code);
        abort_if($supplier === null, 404);
        return $this->resource($supplier);
    }

    public function create(array $data, ?string $idempotencyKey, bool $logAudit = true): array
    {
        return $this->runIdempotent($data, function (int $tenantId) use ($data, $logAudit): array {
            if (!empty($data['customer_code'])) {
                $person = $this->repository->personForCustomerCode($tenantId, $data['customer_code']);
            } elseif (!empty($data['lender_code'])) {
                $person = $this->repository->personForLenderCode($tenantId, $data['lender_code']);
            } else {
                $person = $this->repository->createPerson($tenantId, [
                    'name' => trim($data['name']), 'phone' => $data['phone'] ?? null,
                    'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
                    'note' => null, 'created_by' => Auth::guard('tenantuser')->id() ?? Auth::id(),
                ]);
            }
            if ($person === null) {
                $partyField = !empty($data['lender_code']) ? 'lender_code' : 'customer_code';
                throw ValidationException::withMessages([$partyField => [__('purchasing.party_not_found')]]);
            }
            if ($this->repository->supplierForPerson($tenantId, $person->id) !== null) {
                throw ValidationException::withMessages(['type' => [__('purchasing.supplier_already_linked')]]);
            }
            $supplier = $this->repository->createSupplier($tenantId, [
                'code' => $this->newCode($tenantId), 'person_id' => $person->id,
                'type' => $data['type'], 'contact_name' => $data['contact_name'] ?? null,
                'note' => $data['note'] ?? null, 'is_active' => true,
            ]);
            if ($logAudit) $this->recordAudit('purchasing.supplier.created', PurchaseSupplier::class, $supplier->id, ['supplier_code' => $supplier->code, 'type' => $supplier->type]);
            return $this->resource($supplier->load('person.customer', 'person.lender'));
        }, 201, $idempotencyKey);
    }

    public function update(string $code, array $data): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        return DB::transaction(function () use ($tenantId, $code, $data): array {
            $supplier = $this->repository->supplierByCode($tenantId, $code, true);
            abort_if($supplier === null, 404);
            $supplierFields = array_intersect_key($data, array_flip(['type', 'contact_name', 'note', 'is_active']));
            $personFields = array_intersect_key($data, array_flip(['name', 'phone', 'email', 'address']));
            if (isset($personFields['name'])) $personFields['name'] = trim($personFields['name']);
            if ($personFields !== []) {
                if ($supplier->person->customer !== null || $supplier->person->lender !== null) {
                    throw ValidationException::withMessages(['name' => [__('purchasing.edit_shared_party_elsewhere')]]);
                }
                $this->repository->updatePerson($supplier->person, $personFields);
            }
            if ($supplierFields !== []) $supplier = $this->repository->updateSupplier($supplier, $supplierFields);
            $this->recordAudit('purchasing.supplier.updated', PurchaseSupplier::class, $supplier->id, ['supplier_code' => $supplier->code, 'changed_fields' => array_keys($data)]);
            return $this->resource($supplier->load('person.customer', 'person.lender'));
        });
    }

    public function resource(PurchaseSupplier $supplier): array
    {
        $person = $supplier->person;
        return ['code' => $supplier->code, 'type' => $supplier->type, 'name' => $person?->name,
            'customer_code' => $person?->customer?->code, 'lender_code' => $person?->lender?->code,
            'contact_name' => $supplier->contact_name, 'phone' => $person?->phone, 'email' => $person?->email,
            'address' => $person?->address, 'note' => $supplier->note, 'is_active' => $supplier->is_active];
    }

    private function newCode(int $tenantId): string
    {
        return $this->codeGenerator->generateForTenant($tenantId, 'purchase_suppliers', CarbonImmutable::now());
    }

    private function runIdempotent(array $payload, callable $callback, int $responseCode, ?string $key): array
    {
        $tenantId = $this->resolveCurrentTenantId();
        $record = $this->idempotencyService->reserveOptional('purchasing.supplier.create', $key, $payload);
        if ($record !== null && $this->idempotencyService->isReplay($record)) $this->idempotencyService->replay($record);
        try {
            return DB::transaction(function () use ($callback, $tenantId, $record, $responseCode): array {
                $result = $callback($tenantId);
                if ($record !== null) $this->idempotencyService->markCompleted($record, $responseCode, ['data' => $result]);
                return $result;
            });
        } catch (Throwable $exception) {
            if ($record !== null) $this->idempotencyService->markFailed($record);
            throw $exception;
        }
    }
}
