<?php

namespace App\Models\OwnershipModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents one acquisition lot's closing quantities within a monthly checkpoint.
 * Stores exact owned, sale-reserved, and pledged quantities, including zero balances.
 * The parent checkpoint provides the item, month, cutoff, and movement coverage.
 * These rows support later lot balance calculations without replacing retained movements,
 * current serialized membership, receipt allocations, or source-specific commitments.
 * They contain quantities only; costs and currencies remain on the acquisition lot.
 */
class OwnershipMonthlyCheckpointBalance extends Model
{
    use BelongToTenant;

    // Closing rows are immutable publication data without Eloquent recording timestamps.
    public $timestamps = false;

    // Only stored business fields may be assigned; generated current-membership keys stay database-owned.
    protected $fillable = ['tenant_id', 'ownership_monthly_checkpoint_id', 'acquisition_lot_id', 'owned_quantity', 'reserved_quantity', 'pledged_quantity'];

    // Preserve decimal strings and immutable business dates across model hydration.
    protected $casts = [
        'owned_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'pledged_quantity' => 'decimal:3',
    ];

    public function checkpoint(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(OwnershipMonthlyCheckpoint::class, 'ownership_monthly_checkpoint_id');
    }

    public function acquisitionLot(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(AcquisitionLot::class, 'acquisition_lot_id');
    }
}
