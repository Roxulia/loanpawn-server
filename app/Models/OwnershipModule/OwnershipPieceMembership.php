<?php

namespace App\Models\OwnershipModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents one period during which a specific serialized piece belongs to a lot.
 * Connects the physical Inventory piece to its acquisition lot and receipt allocation.
 * An open period has no ended_at; ending it preserves the earlier ownership history.
 * A database-generated unique current_piece_id permits only one open membership per piece.
 * A valid return or later reacquisition can create another period without erasing the old one.
 */
class OwnershipPieceMembership extends Model
{
    use BelongToTenant;

    // Only stored business fields may be assigned; generated current-membership keys stay database-owned.
    protected $fillable = ['tenant_id', 'owned_item_id', 'acquisition_lot_id', 'ownership_receipt_link_id', 'inventory_serialized_item_id', 'started_at', 'ended_at'];

    // Preserve decimal strings and immutable business dates across model hydration.
    protected $casts = [
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
    ];

    public function acquisitionLot(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(AcquisitionLot::class, 'acquisition_lot_id');
    }

    public function receiptLink(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(OwnershipReceiptLink::class, 'ownership_receipt_link_id');
    }

    public function serializedItem(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(\App\Models\InventoryModule\InventorySerializedItem::class, 'inventory_serialized_item_id');
    }
}
