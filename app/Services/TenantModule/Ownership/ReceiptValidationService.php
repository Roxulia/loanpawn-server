<?php

namespace App\Services\TenantModule\Ownership;

use App\Exceptions\InvalidTenantRequest;
use App\Support\InventoryQuantity;

/** Receipt association/capacity and exact-piece checks, with no persistence or financial effects. */
class ReceiptValidationService
{
    public function validateReceiptLotAssociation($receipt, $lot, $item): void
    {
        // Immutable acquisition source and actual receipt item must agree before capacity is considered.
        if ($lot->source_module !== 'PURCHASING' || $lot->source_type !== 'PurchaseOrderLine'
            || $receipt->orderLine?->code !== $lot->source_code
            || (int) $receipt->inventory_item_id !== (int) $item->id
            || (int) $receipt->tenant_id !== (int) $lot->tenant_id
            || (int) $item->tenant_id !== (int) $lot->tenant_id) {
            throw new InvalidTenantRequest(__('ownership.receipt_link_invalid'));
        }
    }

    public function validateReceiptLinkCapacity($receipt, $lot, iterable $links, int $quantity): void
    {
        // Consumption never reopens original allocation capacity: count linked, not remaining-owned quantity.
        $receiptUsed = $lotUsed = 0;
        foreach ($links as $link) {
            if ((int) $link->purchase_receipt_line_id === (int) $receipt->id) $receiptUsed += InventoryQuantity::parseBalance($link->linked_quantity);
            if ((int) $link->acquisition_lot_id === (int) $lot->id) $lotUsed += InventoryQuantity::parseBalance($link->linked_quantity);
        }
        if ($receiptUsed + $quantity > InventoryQuantity::parse($receipt->received_quantity)
            || $lotUsed + $quantity > InventoryQuantity::parse($lot->acquired_quantity)) {
            throw new InvalidTenantRequest(__('ownership.receipt_link_exceeded'));
        }
    }

    public function validateReceiptPieceSelection(string $mode, int $quantity, array $codes, array $receiptCodes, array $pieces, $members): void
    {
        // Linking retains exact receipt provenance and rejects existing current Ownership memberships.
        if ($mode !== 'SERIALIZED') {
            if ($codes !== []) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
            return;
        }
        if (count($codes) * 1000 !== $quantity || count(array_unique($codes)) !== count($codes) || count($pieces) !== count($codes)
            || array_diff($codes, $receiptCodes) !== []) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
        $owned = $members->keyBy('inventory_serialized_item_id');
        foreach ($pieces as $piece) {
            if ($piece['location_id'] === null) throw new InvalidTenantRequest(__('ownership.piece_selection_invalid'));
            if ($owned->has($piece['id'])) throw new InvalidTenantRequest(__('ownership.piece_already_owned'));
        }
    }
}
