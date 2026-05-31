<?php

namespace App\Services;

use App\DataObjects\RequestObjects\OnlineSyncLogEntry;
use App\DataObjects\RequestObjects\OnlineSyncPushRequest;
use App\DataObjects\ResponseObjects\OnlineSyncLogResult;
use App\DataObjects\ResponseObjects\OnlineSyncPushResult;
use App\Models\CoreModule\TenantAccounting;
use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantDebt;
use App\Models\CoreModule\TenantExpense;
use App\Models\PawnModule\PawnCollateralItem;
use App\Models\PawnModule\PawnInterestPayment;
use App\Models\PawnModule\PawnLoanContractSlip;
use App\Models\PawnModule\PawnRedemption;
use App\Repository\OnlineSyncRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OnlineSyncService extends BaseTenantService
{
    protected const ACTIVITY_INSERT = 'INSERT';
    protected const ACTIVITY_UPDATE = 'UPDATE';
    protected const ACTIVITY_DELETE = 'DELETE';
    protected const ACTIVITY_BATCH_INSERT = 'BATCH_INSERT';
    protected const ACTIVITY_BATCH_UPDATE = 'BATCH_UPDATE';
    protected const ACTIVITY_BATCH_DELETE = 'BATCH_DELETE';

    public function __construct(
        private OnlineSyncRepository $repository,
    ) {
    }

    public function push(OnlineSyncPushRequest $request): OnlineSyncPushResult
    {
        $results = DB::transaction(function () use ($request): array {
            return array_map(
                fn (OnlineSyncLogEntry $entry): OnlineSyncLogResult => $this->applyLog($entry),
                $request->syncLogs
            );
        });

        $applied = count(array_filter($results, fn (OnlineSyncLogResult $result): bool => $result->status === 'applied'));
        $skipped = count(array_filter($results, fn (OnlineSyncLogResult $result): bool => $result->status === 'skipped'));
        $failed = count(array_filter($results, fn (OnlineSyncLogResult $result): bool => $result->status === 'failed'));

        return new OnlineSyncPushResult(
            received: count($request->syncLogs),
            applied: $applied,
            skipped: $skipped,
            failed: $failed,
            results: $results
        );
    }

    protected function applyLog(OnlineSyncLogEntry $entry): OnlineSyncLogResult
    {
        try {
            $mapping = $this->mappingFor($entry->tableName);
            $activityType = strtoupper(trim($entry->activityType));

            if (! $this->isSupportedActivity($activityType)) {
                return $this->failed($entry, 'Unsupported sync activity type.');
            }

            if ($this->isBatchActivity($activityType)) {
                return $this->applyBatch($entry, $mapping, $activityType);
            }

            $recordId = $this->resolveRecordId($entry);
            $incomingRecordData = $this->parseRecordData($entry->recordData);
            $incomingData = $this->prepareData($mapping, $incomingRecordData);
            $existing = $recordId === null ? null : $this->repository->find($mapping['model'], $recordId);

            if ($existing !== null && $this->isIncomingOlderThanServer($entry, $existing)) {
                return new OnlineSyncLogResult(
                    clientLogId: $entry->id,
                    tableName: $entry->tableName,
                    recordId: $entry->recordId,
                    status: 'skipped',
                    message: 'Server version is newer; skipped by last-write-wins timestamp policy.',
                    serverUpdatedAt: $existing->updated_at?->toISOString()
                );
            }

            return match ($activityType) {
                self::ACTIVITY_INSERT => $this->applyInsert($entry, $mapping, $recordId, $incomingData, $existing),
                self::ACTIVITY_UPDATE => $this->applyUpdate($entry, $mapping, $recordId, $incomingData, $existing),
                self::ACTIVITY_DELETE => $this->applyDelete($entry, $existing),
                default => $this->failed($entry, 'Unsupported sync activity type.'),
            };
        } catch (\Throwable $exception) {
            return $this->failed($entry, $exception->getMessage());
        }
    }

    protected function applyInsert(
        OnlineSyncLogEntry $entry,
        array $mapping,
        ?int $recordId,
        array $incomingData,
        ?Model $existing
    ): OnlineSyncLogResult {
        $model = $existing === null
            ? $this->repository->create($mapping['model'], $incomingData, $recordId)
            : $this->repository->update($existing, $incomingData);

        return $this->applied($entry, $model, $existing === null ? 'Created from desktop sync log.' : 'Updated by newer desktop insert log.');
    }

    protected function applyUpdate(
        OnlineSyncLogEntry $entry,
        array $mapping,
        ?int $recordId,
        array $incomingData,
        ?Model $existing
    ): OnlineSyncLogResult {
        if ($existing === null) {
            $model = $this->repository->create($mapping['model'], $incomingData, $recordId);

            return $this->applied($entry, $model, 'Created because desktop update target did not exist on server.');
        }

        return $this->applied(
            $entry,
            $this->repository->update($existing, $incomingData),
            'Updated from desktop sync log.'
        );
    }

    protected function applyDelete(OnlineSyncLogEntry $entry, ?Model $existing): OnlineSyncLogResult
    {
        if ($existing === null) {
            return new OnlineSyncLogResult($entry->id, $entry->tableName, $entry->recordId, 'skipped', 'Record already absent.');
        }

        $this->repository->delete($existing);

        return new OnlineSyncLogResult($entry->id, $entry->tableName, $entry->recordId, 'applied', 'Deleted from desktop sync log.');
    }

    protected function applyBatch(OnlineSyncLogEntry $entry, array $mapping, string $activityType): OnlineSyncLogResult
    {
        $records = $this->parseBatchRecordData($entry->recordData);
        $applied = 0;
        $skipped = 0;

        foreach ($records as $recordData) {
            $recordId = isset($recordData['id']) ? (int) $recordData['id'] : null;
            $existing = $recordId === null ? null : $this->repository->find($mapping['model'], $recordId);

            if ($existing !== null && $this->isIncomingOlderThanServer($entry, $existing)) {
                $skipped++;
                continue;
            }

            if ($activityType === self::ACTIVITY_BATCH_DELETE) {
                if ($existing === null) {
                    $skipped++;
                    continue;
                }

                $this->repository->delete($existing);
                $applied++;
                continue;
            }

            $incomingData = $this->prepareData($mapping, $recordData);

            if ($existing === null) {
                $this->repository->create($mapping['model'], $incomingData, $recordId);
            } else {
                $this->repository->update($existing, $incomingData);
            }

            $applied++;
        }

        return new OnlineSyncLogResult(
            clientLogId: $entry->id,
            tableName: $entry->tableName,
            recordId: $entry->recordId,
            status: $applied > 0 ? 'applied' : 'skipped',
            message: "Batch sync processed. applied={$applied}, skipped={$skipped}."
        );
    }

    protected function prepareData(array $mapping, array $recordData): array
    {
        $data = ['tenant_id' => $this->resolveCurrentTenantId()];

        foreach ($mapping['fields'] as $desktopField => $serverField) {
            if (! array_key_exists($desktopField, $recordData)) {
                continue;
            }

            $data[$serverField] = $this->normalizeValue($recordData[$desktopField]);
        }

        if (! isset($data['type'])) {
            $data['type'] = match ($mapping['table'] ?? null) {
                'JewelleryItems' => 'Jewellery',
                'NormalItems' => 'Normal',
                'CollateralItems', 'SlipXItems' => $this->booleanValue($recordData['is_jewelry'] ?? false) ? 'Jewellery' : 'Normal',
                default => $data['type'] ?? null,
            };
        }

        if (($mapping['table'] ?? null) === 'Accounting' && isset($data['transaction_type'])) {
            $data['transaction_type'] = match (strtoupper((string) $data['transaction_type'])) {
                'INCOME' => 'incoming',
                'EXPENSE' => 'outgoing',
                default => strtolower((string) $data['transaction_type']),
            };
        }

        foreach (['status', 'item_status'] as $statusField) {
            if (isset($data[$statusField])) {
                $data[$statusField] = strtolower((string) $data[$statusField]);
            }
        }

        return $this->normalizeDateFields($data);
    }

    protected function parseRecordData(?string $recordData): array
    {
        $recordData = trim((string) $recordData);

        if ($recordData === '') {
            return [];
        }

        $json = json_decode($recordData, true);
        if (is_array($json)) {
            return $json;
        }

        if (! str_starts_with($recordData, '{') || ! str_ends_with($recordData, '}')) {
            return [];
        }

        $inner = substr($recordData, 1, -1);
        if (trim($inner) === '') {
            return [];
        }

        $result = [];
        foreach (explode(', ', $inner) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if ($key === null || $key === '') {
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    protected function parseBatchRecordData(?string $recordData): array
    {
        $recordData = trim((string) $recordData);

        if ($recordData === '') {
            return [];
        }

        $json = json_decode($recordData, true);
        if (is_array($json)) {
            return array_is_list($json) ? $json : [$json];
        }

        if (! str_starts_with($recordData, '[') || ! str_ends_with($recordData, ']')) {
            return [];
        }

        $inner = substr($recordData, 1, -1);
        if (trim($inner) === '') {
            return [];
        }

        preg_match_all('/\{[^{}]*\}/', $inner, $matches);

        return array_map(
            fn (string $item): array => $this->parseRecordData($item),
            $matches[0]
        );
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if ($value === null || $value === 'null') {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            $lower = strtolower($trimmed);

            if (in_array($lower, ['true', 'false'], true)) {
                return $lower === 'true';
            }

            if (is_numeric($trimmed)) {
                return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
            }

            return $trimmed;
        }

        return $value;
    }

    protected function normalizeDateFields(array $data): array
    {
        foreach (['created_date', 'expire_date', 'last_interest_added_date', 'last_interest_paid_date', 'payment_date', 'start_period', 'end_period', 'redemption_date'] as $field) {
            if (! isset($data[$field])) {
                continue;
            }

            $data[$field] = CarbonImmutable::parse($data[$field])->toDateString();
        }

        return $data;
    }

    protected function resolveRecordId(OnlineSyncLogEntry $entry): ?int
    {
        if ($entry->recordId !== null && trim($entry->recordId) !== '') {
            return (int) $entry->recordId;
        }

        $recordData = $this->parseRecordData($entry->recordData);

        return isset($recordData['id']) ? (int) $recordData['id'] : null;
    }

    protected function isIncomingOlderThanServer(OnlineSyncLogEntry $entry, Model $existing): bool
    {
        if ($existing->updated_at === null) {
            return false;
        }

        $incomingTime = $this->incomingTimestamp($entry);

        return $incomingTime !== null && $incomingTime->lt(CarbonImmutable::parse($existing->updated_at));
    }

    protected function incomingTimestamp(OnlineSyncLogEntry $entry): ?CarbonImmutable
    {
        $recordData = $this->parseRecordData($entry->recordData);
        $timestamp = $recordData['updated_at'] ?? $recordData['created_at'] ?? $entry->createdAt;

        return $timestamp === null || trim((string) $timestamp) === ''
            ? null
            : CarbonImmutable::parse($timestamp);
    }

    protected function mappingFor(string $tableName): array
    {
        $normalized = strtolower(trim($tableName));
        $mappings = $this->syncMappings();

        if (! isset($mappings[$normalized])) {
            throw new \InvalidArgumentException('Unsupported sync table: '.$tableName);
        }

        return $mappings[$normalized] + ['table' => $mappings[$normalized]['table'] ?? $tableName];
    }

    protected function syncMappings(): array
    {
        return [
            'customerdata' => [
                'table' => 'CustomerData',
                'model' => TenantCustomer::class,
                'fields' => [
                    'name' => 'name',
                    'email' => 'email',
                    'phone' => 'phone',
                    'address' => 'address',
                    'trust_score' => 'trust_score',
                    'note' => 'note',
                ],
            ],
            'collateralitems' => [
                'table' => 'CollateralItems',
                'model' => PawnCollateralItem::class,
                'fields' => [
                    'loan_contract_id' => 'loan_contract_id',
                    'type' => 'type',
                    'name' => 'name',
                    'item_name' => 'name',
                    'description' => 'description',
                    'item_description' => 'description',
                    'brand_name' => 'brand_name',
                    'image_url' => 'image_url',
                    'estimated_value' => 'estimated_value',
                    'material_type_id' => 'material_type_id',
                    'kyat' => 'kyat',
                    'pal' => 'pal',
                    'yway' => 'yway',
                    'item_status' => 'item_status',
                    'contains_gemstones' => 'contains_gemstones',
                    'gemstone_details' => 'gemstone_details',
                    'quantity' => 'quantity',
                    'minimum_retail_price' => 'minimum_retail_price',
                    'is_deleted' => 'is_deleted',
                ],
            ],
            'loancontractslip' => [
                'table' => 'LoanContractSlip',
                'model' => PawnLoanContractSlip::class,
                'fields' => [
                    'slip_id' => 'slip_no',
                    'customer_id' => 'customer_id',
                    'loan_amount' => 'loan_amount',
                    'interest_rate' => 'interest_rate',
                    'interest_rate_id' => 'interest_type_id',
                    'created_date' => 'created_date',
                    'expire_date' => 'expire_date',
                    'last_interest_added_date' => 'last_interest_added_date',
                    'last_interest_paid_date' => 'last_interest_paid_date',
                    'status' => 'status',
                    'notes' => 'notes',
                    'created_by' => 'created_by',
                    'expiry_quota' => 'expiry_quota',
                    'expiry_quota_type' => 'expiry_quota_type',
                ],
            ],
            'normalitems' => [
                'table' => 'NormalItems',
                'model' => PawnCollateralItem::class,
                'fields' => [
                    'item_name' => 'name',
                    'item_description' => 'description',
                    'brand_name' => 'brand_name',
                    'image_url' => 'image_url',
                    'estimated_value' => 'estimated_value',
                    'item_status' => 'item_status',
                ],
            ],
            'jewelleryitems' => [
                'table' => 'JewelleryItems',
                'model' => PawnCollateralItem::class,
                'fields' => [
                    'name' => 'name',
                    'image_url' => 'image_url',
                    'material_type_id' => 'material_type_id',
                    'kyat' => 'kyat',
                    'pal' => 'pal',
                    'yway' => 'yway',
                    'item_status' => 'item_status',
                    'contains_gemstones' => 'contains_gemstones',
                    'gemstone_details' => 'gemstone_details',
                ],
            ],
            'slipxitems' => [
                'table' => 'SlipXItems',
                'model' => PawnCollateralItem::class,
                'fields' => [
                    'loan_contract_id' => 'loan_contract_id',
                    'quantity' => 'quantity',
                    'minimum_retail_price' => 'minimum_retail_price',
                    'is_deleted' => 'is_deleted',
                ],
            ],
            'interestpayment' => [
                'table' => 'InterestPayment',
                'model' => PawnInterestPayment::class,
                'fields' => [
                    'slip_id' => 'slip_id',
                    'payment_amount' => 'payment_amount',
                    'change_amount' => 'change_amount',
                    'calculated_interest' => 'calculated_interest',
                    'payment_date' => 'payment_date',
                    'notes' => 'notes',
                    'created_by' => 'created_by',
                    'start_period' => 'start_period',
                    'end_period' => 'end_period',
                    'is_paid' => 'is_paid',
                ],
            ],
            'redemption' => [
                'table' => 'Redemption',
                'model' => PawnRedemption::class,
                'fields' => [
                    'slip_id' => 'slip_id',
                    'gross_amount' => 'gross_amount',
                    'net_amount' => 'net_amount',
                    'interest_amount' => 'interest_amount',
                    'received_amount' => 'received_amount',
                    'change_amount' => 'change_amount',
                    'redemption_date' => 'redemption_date',
                    'notes' => 'notes',
                    'created_by' => 'created_by',
                ],
            ],
            'debts' => [
                'table' => 'Debts',
                'model' => TenantDebt::class,
                'fields' => [
                    'slip_id' => 'slip_id',
                    'amount' => 'amount',
                    'description' => 'description',
                    'tag' => 'tag',
                    'is_paid' => 'is_paid',
                    'accepted_by' => 'accepted_by',
                    'created_by' => 'created_by',
                ],
            ],
            'expenses' => [
                'table' => 'Expenses',
                'model' => TenantExpense::class,
                'fields' => [
                    'description' => 'description',
                    'amount' => 'amount',
                    'created_by' => 'created_by',
                    'expense_type_id' => 'expense_type_id',
                ],
            ],
            'accounting' => [
                'table' => 'Accounting',
                'model' => TenantAccounting::class,
                'fields' => [
                    'description' => 'description',
                    'transaction_type' => 'transaction_type',
                    'amount' => 'amount',
                    'created_by' => 'created_by',
                    'reference_id' => 'reference_id',
                    'reference_type' => 'reference_type',
                ],
            ],
        ];
    }

    protected function isSupportedActivity(string $activityType): bool
    {
        return in_array($activityType, [
            self::ACTIVITY_INSERT,
            self::ACTIVITY_UPDATE,
            self::ACTIVITY_DELETE,
            self::ACTIVITY_BATCH_INSERT,
            self::ACTIVITY_BATCH_UPDATE,
            self::ACTIVITY_BATCH_DELETE,
        ], true);
    }

    protected function isBatchActivity(string $activityType): bool
    {
        return in_array($activityType, [
            self::ACTIVITY_BATCH_INSERT,
            self::ACTIVITY_BATCH_UPDATE,
            self::ACTIVITY_BATCH_DELETE,
        ], true);
    }

    protected function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    protected function applied(OnlineSyncLogEntry $entry, Model $model, string $message): OnlineSyncLogResult
    {
        return new OnlineSyncLogResult(
            clientLogId: $entry->id,
            tableName: $entry->tableName,
            recordId: (string) $model->getKey(),
            status: 'applied',
            message: $message,
            serverUpdatedAt: $model->updated_at?->toISOString()
        );
    }

    protected function failed(OnlineSyncLogEntry $entry, string $message): OnlineSyncLogResult
    {
        return new OnlineSyncLogResult($entry->id, $entry->tableName, $entry->recordId, 'failed', $message);
    }
}
