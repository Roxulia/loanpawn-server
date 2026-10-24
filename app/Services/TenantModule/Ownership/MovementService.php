<?php

namespace App\Services\TenantModule\Ownership;

use App\Exceptions\InvalidTenantRequest;
use App\Models\OwnershipModule\OwnershipMovement;
use App\Repository\OwnershipRepository;
use App\Services\BaseTenantService;
use App\Support\{InventoryQuantity, OwnershipQuantity};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/** Exact append helper shared by current and compatibility callers; no physical or financial posting. */
class MovementService extends BaseTenantService
{
    public function __construct(private OwnershipRepository $repository) {}

    public function buildOwnershipQuantityTransition(string $action, mixed $quantity, array $references = []): array
    {
        // Keep one exact transition mapping shared by active mutations and pure unit tests.
        $quantity = InventoryQuantity::format(InventoryQuantity::parse($quantity));
        [$type, $owned, $reserved, $pledged] = match ($action) {
            'reserve' => ['SALE_RESERVE', 0, 1, 0],
            'release' => ['SALE_RELEASE', 0, -1, 0],
            'consume' => ['SALE_CONSUME', -1, -1, 0],
            'pledge' => ['PLEDGE', 0, 0, 1],
            'unpledge' => ['RELEASE', 0, 0, -1],
            'forfeit' => ['FORFEITURE', -1, 0, -1],
            default => throw new InvalidTenantRequest(__('ownership.commitment_source_invalid')),
        };
        $delta = fn (int $sign): string => $sign === 0 ? '0.000' : ($sign < 0 ? '-' : '').$quantity;
        return [...$references, 'movement_type' => $type, 'quantity' => $quantity,
            'owned_delta' => $delta($owned), 'reserved_delta' => $delta($reserved), 'pledged_delta' => $delta($pledged)];
    }

    public function appendOwnershipMovement(array $data): OwnershipMovement
    {
        // Keep operation quantity and each signed component exact, bounded and independent of float sums.
        $data['quantity'] = InventoryQuantity::format(InventoryQuantity::parse($data['quantity']));
        foreach (['owned_delta', 'reserved_delta', 'pledged_delta'] as $field) {
            $value = (string) ($data[$field] ?? '0.000');
            $negative = str_starts_with($value, '-');
            $absolute = OwnershipQuantity::normalizeBalance($negative ? substr($value, 1) : $value);
            if (OwnershipQuantity::compare($absolute, $data['quantity']) > 0) {
                throw new InvalidTenantRequest(__('ownership.balance_invalid'));
            }
            $data[$field] = $negative && $absolute !== '0.000' ? '-'.$absolute : $absolute;
        }

        // Caller/source business date is distinct from the server's authoritative recording timestamp.
        $data['created_by'] ??= Auth::guard('tenantuser')->id();
        $data['occurred_at'] ??= CarbonImmutable::now('UTC');
        return $this->repository->createMovement($this->resolveCurrentTenantId(), $data);
    }
}
