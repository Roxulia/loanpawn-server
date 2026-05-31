<?php

namespace App\Repository;

use App\Models\CoreModule\TenantIdempotencyKey;

class TenantIdempotencyRepository
{
    public function create(array $data): TenantIdempotencyKey
    {
        return TenantIdempotencyKey::query()->create($data);
    }

    public function findByOperationAndKey(string $operation, string $idempotencyKey): ?TenantIdempotencyKey
    {
        return TenantIdempotencyKey::query()
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    public function findByOperationAndKeyWithLock(string $operation, string $idempotencyKey): ?TenantIdempotencyKey
    {
        return TenantIdempotencyKey::query()
            ->where('operation', $operation)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    public function markCompleted(
        TenantIdempotencyKey $idempotencyRecord,
        int $responseCode,
        array $responseBody,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): TenantIdempotencyKey {
        return $this->update($idempotencyRecord, [
            'status' => 'completed',
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function markFailed(TenantIdempotencyKey $idempotencyRecord): TenantIdempotencyKey
    {
        return $this->update($idempotencyRecord, [
            'status' => 'failed',
        ]);
    }

    public function update(TenantIdempotencyKey $idempotencyRecord, array $data): TenantIdempotencyKey
    {
        $idempotencyRecord->update($data);

        return $idempotencyRecord->refresh();
    }
}
