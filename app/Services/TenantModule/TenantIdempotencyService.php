<?php

namespace App\Services\TenantModule;

use App\Exceptions\IdempotencyKeyConflict;
use App\Exceptions\IdempotencyRequestProcessing;
use App\Exceptions\IdempotentReplayResponse;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\TenantIdempotencyKey;
use App\Repository\TenantIdempotencyRepository;
use App\Services\BaseTenantService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class TenantIdempotencyService extends BaseTenantService
{
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        private TenantIdempotencyRepository $repository,
    ) {
    }

    public function reserve(string $operation, ?string $idempotencyKey, mixed $payload): TenantIdempotencyKey
    {
        $normalizedKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $requestHash = $this->hashPayload($payload);

        return DB::transaction(function () use ($operation, $normalizedKey, $requestHash): TenantIdempotencyKey {
            $existingRecord = $this->repository->findByOperationAndKeyWithLock($operation, $normalizedKey);

            if ($existingRecord !== null) {
                $this->assertSameRequestPayload($existingRecord, $requestHash);

                if ($existingRecord->status === self::STATUS_PROCESSING) {
                    throw new IdempotencyRequestProcessing();
                }

                if ($existingRecord->status === self::STATUS_FAILED) {
                    return $this->repository->update($existingRecord, [
                        'status' => self::STATUS_PROCESSING,
                        'response_code' => null,
                        'response_body' => null,
                        'resource_type' => null,
                        'resource_id' => null,
                    ]);
                }

                return $existingRecord;
            }

            return $this->repository->create([
                'tenant_id' => $this->resolveCurrentTenantId(),
                'operation' => $operation,
                'idempotency_key' => $normalizedKey,
                'request_hash' => $requestHash,
                'status' => self::STATUS_PROCESSING,
                'expires_at' => CarbonImmutable::now()->addDay(),
            ]);
        });
    }

    public function reserveOptional(string $operation, ?string $idempotencyKey, mixed $payload): ?TenantIdempotencyKey
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        return $this->reserve($operation, $idempotencyKey, $payload);
    }

    public function markCompleted(
        TenantIdempotencyKey $idempotencyRecord,
        int $responseCode,
        array $responseBody,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): TenantIdempotencyKey {
        return $this->repository->markCompleted(
            $idempotencyRecord,
            $responseCode,
            $responseBody,
            $resourceType,
            $resourceId
        );
    }

    public function markFailed(TenantIdempotencyKey $idempotencyRecord): TenantIdempotencyKey
    {
        return $this->repository->markFailed($idempotencyRecord);
    }

    public function isReplay(TenantIdempotencyKey $idempotencyRecord): bool
    {
        return $idempotencyRecord->status === self::STATUS_COMPLETED;
    }

    public function replay(TenantIdempotencyKey $idempotencyRecord): never
    {
        throw new IdempotentReplayResponse(
            $idempotencyRecord->response_body ?? [],
            (int) ($idempotencyRecord->response_code ?? 200)
        );
    }

    public function hashPayload(mixed $payload): string
    {
        return hash('sha256', json_encode($this->normalizePayload($payload), JSON_THROW_ON_ERROR));
    }

    protected function normalizeIdempotencyKey(?string $idempotencyKey): string
    {
        $normalizedKey = trim((string) $idempotencyKey);

        if ($normalizedKey === '') {
            throw new InvalidTenantRequest('Idempotency-Key header is required.');
        }

        if (mb_strlen($normalizedKey) > 120) {
            throw new InvalidTenantRequest('Idempotency-Key header must not exceed 120 characters.');
        }

        return $normalizedKey;
    }

    protected function assertSameRequestPayload(TenantIdempotencyKey $idempotencyRecord, string $requestHash): void
    {
        if (! hash_equals((string) $idempotencyRecord->request_hash, $requestHash)) {
            throw new IdempotencyKeyConflict();
        }
    }

    protected function normalizePayload(mixed $payload): mixed
    {
        if ($payload instanceof DateTimeInterface) {
            return $payload->format(DateTimeInterface::ATOM);
        }

        if (is_object($payload)) {
            $payload = get_object_vars($payload);
        }

        if (! is_array($payload)) {
            return $payload;
        }

        ksort($payload);

        foreach ($payload as $key => $value) {
            $payload[$key] = $this->normalizePayload($value);
        }

        return $payload;
    }
}
