<?php

namespace App\Models\OwnershipModule;

use App\Models\PurchasingModule\PurchaseReceiptLine;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a quantity from a purchase receipt linked to an existing acquisition lot.
 * Records how much was originally linked and how much of that allocation is still owned.
 * It connects physical receipt to ownership already acquired through a settled purchase;
 * creating the link must not acquire the goods a second time.
 * Multiple partial links are allowed. Services must validate the source and remaining capacity.
 */
class OwnershipReceiptLink extends Model
{
    use BelongToTenant;

    // Only stored business fields may be assigned; generated current-membership keys stay database-owned.
    protected $fillable = ['tenant_id', 'code', 'owned_item_id', 'acquisition_lot_id', 'purchase_receipt_line_id', 'linked_quantity', 'remaining_owned_quantity', 'idempotency_record_id'];

    // Preserve decimal strings and immutable business dates across model hydration.
    protected $casts = [
        'linked_quantity' => 'decimal:3',
        'remaining_owned_quantity' => 'decimal:3',
    ];

    public function ownedItem(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(OwnedItem::class, 'owned_item_id');
    }

    public function acquisitionLot(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(AcquisitionLot::class, 'acquisition_lot_id');
    }

    public function purchaseReceiptLine(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id');
    }
}
