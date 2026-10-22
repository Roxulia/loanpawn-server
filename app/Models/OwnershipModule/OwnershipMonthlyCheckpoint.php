<?php

namespace App\Models\OwnershipModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents the saved closing Ownership quantities for one item and completed month.
 * Stores owned, sale-reserved, and pledged totals together with the saved timezone,
 * UTC cutoff, movement watermark, and completion time.
 * Later balance queries can use this checkpoint plus uncovered movements instead
 * of summing the entire ledger. Its child rows hold the individual lot closings.
 * It is derived quantity data; live receipt links and source commitments remain authoritative.
 */
class OwnershipMonthlyCheckpoint extends Model
{
    use BelongToTenant;

    // Only stored business fields may be assigned; generated current-membership keys stay database-owned.
    protected $fillable = ['tenant_id', 'owned_item_id', 'month', 'timezone', 'cutoff_at', 'movement_watermark', 'completed_at', 'owned_quantity', 'reserved_quantity', 'pledged_quantity'];

    // Preserve decimal strings and immutable business dates across model hydration.
    protected $casts = [
        'month' => 'immutable_date',
        'cutoff_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'movement_watermark' => 'integer',
        'owned_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'pledged_quantity' => 'decimal:3',
    ];

    public function ownedItem(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(OwnedItem::class, 'owned_item_id');
    }
}
