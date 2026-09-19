<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\TenantLenderUpsert;
use App\DataObjects\ResponseObjects\TenantLenderDetail;
use App\Exceptions\AlreadyUpdatedException;
use App\Exceptions\InvalidTenantRequest;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantLender;
use App\Repository\TenantLenderRepository;
use App\Services\BaseTenantService;
use App\Services\TableIdGenerationService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TenantLenderService extends BaseTenantService
{
    public function __construct(
        private TenantLenderRepository $repository,
        private TenantUserPermissionService $permissionService,
        private TableIdGenerationService $tableIdGenerationService,
        private TenantAuditLogService $auditLogService,
    ) {}

    public function list(int $perPage, ?string $search): array
    {
        // Authorization and mapping of the lender directory
        $this->permissionService->authorizeLenderList();
        $paginator = $this->repository->paginate($perPage, $search);
        return [
            'data' => collect($paginator->items())->map(fn (TenantLender $lender) => TenantLenderDetail::fromModel($lender)->toArray())->all(),
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
        ];
    }

    public function detail(string $code): TenantLenderDetail
    {
        $this->permissionService->authorizeLenderList();
        return TenantLenderDetail::fromModel($this->find($code));
    }

    public function create(TenantLenderUpsert $request): TenantLenderDetail
    {
        // Creation of one shared person and both domain profiles
        $this->permissionService->authorizeLenderCreate();
        $tenantId = app(TenantContext::class)->id();
        $userId = Auth::guard('tenantuser')->id();
        $lender = DB::transaction(function () use ($request, $tenantId, $userId): TenantLender {
            $person = $this->repository->findPerson($tenantId, $request->nrc, $request->email, $request->phone);
            if ($person === null) {
                $person = $this->repository->createPerson(['tenant_id' => $tenantId, 'name' => $request->name, 'nrc' => $request->nrc, 'email' => $request->email, 'phone' => $request->phone, 'address' => $request->address, 'note' => $request->note, 'created_by' => $userId]);
            }

            $lender = $this->repository->lenderForPerson($person->id, true);
            if ($lender !== null && ! $lender->trashed()) return $this->repository->findByCode($lender->code);
            $lender = $lender?->trashed()
                ? $this->repository->restoreLender($lender)
                : $this->repository->createLender(['tenant_id' => $tenantId, 'person_id' => $person->id, 'code' => $this->tableIdGenerationService->generate('tenant_lenders', CarbonImmutable::now()), 'created_by' => $userId]);

            if ($this->repository->customerForPerson($person->id, true) === null) {
                $this->repository->createCustomer(['tenant_id' => $tenantId, 'person_id' => $person->id, 'code' => $this->tableIdGenerationService->generate('tenant_customers', CarbonImmutable::now()), 'name' => $person->name, 'nrc' => $person->nrc, 'email' => $person->email, 'phone' => $person->phone, 'address' => $person->address, 'note' => $person->note, 'trust_score' => TenantCustomer::DEFAULT_TRUST_SCORE, 'created_by' => $userId]);
            }
            $this->auditLogService->log('tenant_lender.created', TenantLender::class, $lender->id, ['lender' => $lender->code]);
            return $this->repository->findByCode($lender->code);
        });
        return TenantLenderDetail::fromModel($lender);
    }

    public function update(string $code, TenantLenderUpsert $request): TenantLenderDetail
    {
        // Update of the shared identity from the lender entry point
        $this->permissionService->authorizeLenderUpdate();
        $lender = DB::transaction(function () use ($code, $request): TenantLender {
            $lender = $this->repository->findByCode($code, true) ?? throw new TenantNotFound('Tenant lender not found.');
            if ((int) $lender->update_key !== $request->updateKey) throw new AlreadyUpdatedException('This item is already Updated.Please refresh');
            $person = $this->repository->updatePerson($lender->person, ['name' => $request->name, 'nrc' => $request->nrc, 'email' => $request->email, 'phone' => $request->phone, 'address' => $request->address, 'note' => $request->note, 'update_key' => $lender->person->update_key + 1]);
            if ($customer = $this->repository->customerForPerson($person->id, true)) $this->repository->mirrorCustomerIdentity($customer, $person);
            $lender->update(['update_key' => $lender->update_key + 1]);
            $this->auditLogService->log('tenant_lender.updated', TenantLender::class, $lender->id, ['lender' => $lender->code]);
            return $this->repository->findByCode($code);
        });
        return TenantLenderDetail::fromModel($lender);
    }

    public function delete(string $code): void
    {
        $this->permissionService->authorizeLenderDelete();
        $lender = $this->find($code);
        if ($this->repository->hasUnpaidLoans($lender)) throw new InvalidTenantRequest('A lender with unpaid business loans cannot be deleted.');
        DB::transaction(function () use ($lender): void { $this->repository->delete($lender); $this->auditLogService->log('tenant_lender.deleted', TenantLender::class, $lender->id, ['lender' => $lender->code]); });
    }

    public function resolveByCode(?string $code): ?TenantLender { return $code === null || trim($code) === '' ? null : $this->find($code); }
    private function find(string $code): TenantLender { return $this->repository->findByCode($code) ?? throw new TenantNotFound('Tenant lender not found.'); }
}
