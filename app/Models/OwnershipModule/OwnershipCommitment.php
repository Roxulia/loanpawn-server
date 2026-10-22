<?php

namespace App\Models\OwnershipModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents quantity reserved for a sale or pledged against a particular source.
 * Stores the item, lot, commitment kind, source reference, and original and remaining quantity.
 * It lets services release or consume the named source's quantity without using
 * another sale's reservation or another loan's pledge.
 * These are live source commitments; the overall Ownership balance comes from the ledger.
 */
class OwnershipCommitment extends Model
{
    use BelongToTenant;

    // Only stored business fields may be assigned; generated current-membership keys stay database-owned.
    protected $fillable = ['tenant_id', 'owned_item_id', 'acquisition_lot_id', 'kind', 'source_type', 'source_code', 'quantity', 'remaining_quantity'];

    // Preserve decimal strings and immutable business dates across model hydration.
    protected $casts = [
        'quantity' => 'decimal:3',
        'remaining_quantity' => 'decimal:3',
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
}
