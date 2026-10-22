<?php

namespace App\Models\OwnershipModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents one occasion on which the tenant acquired an item.
 * Stores the original source, date, quantity, unit cost, estimated value, and currency.
 * Separate purchases produce separate lots even when they share the same owned item.
 * These values are historical snapshots; current remaining quantities come from
 * Ownership movements and checkpoints. Receipt links identify which acquired goods arrived.
 */
class AcquisitionLot extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id', 'code', 'owned_item_id', 'source_module', 'source_type', 'source_code', 'acquired_at',
        'acquired_quantity', 'unit_cost_basis', 'estimated_unit_value', 'currency_code', 'description', 'created_by',
    ];

    protected $casts = [
        'acquired_at' => 'immutable_date',
        'acquired_quantity' => 'decimal:3',
        'unit_cost_basis' => 'decimal:4',
        'estimated_unit_value' => 'decimal:4',
    ];

    public function ownedItem(): BelongsTo
    {
        return $this->belongsTo(OwnedItem::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(OwnershipMovement::class);
    }
}
