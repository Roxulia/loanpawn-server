<?php

namespace App\Models\OwnershipModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents one recorded change to owned, sale-reserved, or pledged quantity.
 * Stores signed quantity changes and the source that caused them against an item and lot.
 * For example, a sale reservation changes reserved quantity without reducing ownership.
 * Movements form the retained ledger used with monthly checkpoints to calculate balances.
 * This model stores and casts the record; validation, locking, and posting belong to services.
 */
class OwnershipMovement extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id', 'code', 'owned_item_id', 'acquisition_lot_id', 'movement_type', 'quantity', 'owned_delta',
        'pledged_delta', 'reserved_delta', 'source_module', 'source_type', 'source_code', 'idempotency_record_id', 'created_by', 'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'owned_delta' => 'decimal:3',
        'pledged_delta' => 'decimal:3',
        'reserved_delta' => 'decimal:3',
        'occurred_at' => 'immutable_datetime',
    ];

    public function ownedItem(): BelongsTo
    {
        return $this->belongsTo(OwnedItem::class);
    }

    public function acquisitionLot(): BelongsTo
    {
        return $this->belongsTo(AcquisitionLot::class);
    }
}
