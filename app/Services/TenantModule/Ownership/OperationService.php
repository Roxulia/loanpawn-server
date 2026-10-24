<?php

namespace App\Services\TenantModule\Ownership;

use App\DTOs\Ownership\Internal\ResponseObjects\OwnershipMutationResult;
use App\DTOs\Ownership\Public\ResponseObjects\OwnershipResult;
use App\Exceptions\TenantNotFound;
use App\Models\CoreModule\TenantIdempotencyKey;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Services\TenantModule\TenantIdempotencyService;
use Closure;
use Illuminate\Support\Facades\DB;

/** One atomic mutation/audit/completion boundary; internal replay returns normally. */
class OperationService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository, private TenantIdempotencyService $idempotency) {}

    public function runAtomicOwnershipOperation(string $operation, ?string $key, array $payload, Closure $mutation,
        int $responseCode = 200, string $auditAction = 'ownership.changed', bool $publicReplay = false): OwnershipResult
    {
        // Preserve caller savepoints and outer retry ownership; never generate nested replay keys.
        return DB::transaction(function () use ($operation, $key, $payload, $mutation, $responseCode, $auditAction, $publicReplay): OwnershipResult {
            $record = $this->idempotency->reserveOptional($operation, $key, $payload);
            if ($record !== null && $this->idempotency->isReplay($record)) {
                return $this->restoreOwnershipReplayResult($record, $publicReplay);
            }
            $outcome = $this->attachMutationAuditTarget($mutation($record?->id));
            $this->recordAudit($auditAction, get_class($outcome->owner), (int) $outcome->owner->id, $outcome->response->toArray());
            if ($record !== null) $this->idempotency->markCompleted($record, $responseCode, ['data' => $outcome->response->toArray()]);
            return $outcome->response;
        });
    }

    private function restoreOwnershipReplayResult(TenantIdempotencyKey $record, bool $publicReplay): OwnershipResult
    {
        // Rebuild the typed result so MySQL JSON key ordering cannot change the HTTP replay shape.
        $body = $record->response_body['data'] ?? $record->response_body;
        $result = new OwnershipResult($body['owned_item_code'], $body['update_key'], $body['lot_codes'],
            $body['movement_codes'], $body['receipt_link_codes'], $body['serialized_item_codes']);
        if ($publicReplay) {
            // Normalize the in-memory response only; completed records and their effects remain unchanged.
            $record->response_body = ['data' => $result->toArray()];
            $this->idempotency->replay($record);
        }
        return $result;
    }

    private function attachMutationAuditTarget(OwnershipMutationResult|OwnershipResult $result): OwnershipMutationResult
    {
        // Production mutations reuse their already-resolved owner; response-only callers keep the previous fallback.
        $tenant = $this->resolveCurrentTenantId();
        if ($result instanceof OwnershipResult) {
            $owner = $this->repository->findOwnedByCode($tenant, $result->ownedItemCode)
                ?? throw new TenantNotFound(__('ownership.not_found'));
            return new OwnershipMutationResult($result, $owner);
        }
        if ((int) $result->owner->tenant_id !== $tenant || $result->owner->code !== $result->response->ownedItemCode) {
            throw new TenantNotFound(__('ownership.not_found'));
        }
        return $result;
    }
}
