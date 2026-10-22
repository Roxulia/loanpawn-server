<?php

namespace App\DTOs\Ownership\Public\RequestObjects;

use App\DataObjects\RequestObjects\InventoryStockCommand;

use App\Exceptions\InvalidTenantRequest;

/**
 * Selects received purchase goods to link to an existing acquisition lot.
 * Carries exact quantity, selected piece codes, the expected aggregate version,
 * and the idempotency key supplied separately by the caller.
 * Item and lot references come from the service/path; tenant, payment qualification,
 * receipt association, and available linking capacity must be resolved by services.
 */
final class OwnershipReceiptLinkRequest extends InventoryStockCommand
{
    public string $purchaseReceiptLineCode;
    /** Distinct received piece codes, e.g. ["PIECE-A", "PIECE-B"]; empty for quantity-tracked goods. */
    public array $serializedItemCodes;

    public function __construct(
        string $purchaseReceiptLineCode,
        string|int|float $quantity,
        public int $updateKey,
        array $serializedItemCodes = [],
        ?string $idempotencyKey = null,
    ) {
        // Reuse Inventory's exact quantity and public-code normalization helpers.
        parent::__construct($quantity);
        $this->purchaseReceiptLineCode = trim($purchaseReceiptLineCode);
        if ($this->purchaseReceiptLineCode === '' || $updateKey < 0) {
            throw new InvalidTenantRequest(__('ownership.receipt_link_invalid'));
        }
        $this->serializedItemCodes = self::normalizeSerializedItemCodes($serializedItemCodes);

        // Reject duplicates after trimming instead of silently changing the requested selection.
        if (count(array_unique($this->serializedItemCodes, SORT_STRING)) !== count($this->serializedItemCodes)) {
            throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
        }
        $this->idempotencyKey = $idempotencyKey;
    }

    public static function fromValidated(array $data, ?string $idempotencyKey = null): self
    {
        // Keep zero as a valid initial version; reject missing/invalid values without silently casting to zero.
        $version = $data['update_key'] ?? null;
        if ((! is_int($version) && ! is_string($version))
            || filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new InvalidTenantRequest(__('ownership.receipt_link_invalid'));
        }

        // Allowlist payload values; source authority and payload replay keys are never hydrated.
        return new self(
            purchaseReceiptLineCode: $data['purchase_receipt_line_code'],
            quantity: $data['quantity'],
            updateKey: (int) $version,
            serializedItemCodes: $data['serialized_item_codes'] ?? [],
            idempotencyKey: $idempotencyKey,
        );
    }

    public function toArray(): array
    {
        // Serialize only the link payload; replay identity is a separate request boundary.
        return [
            'purchase_receipt_line_code' => $this->purchaseReceiptLineCode,
            'quantity' => $this->quantity,
            'serialized_item_codes' => $this->serializedItemCodes,
            'update_key' => $this->updateKey,
        ];
    }
}
