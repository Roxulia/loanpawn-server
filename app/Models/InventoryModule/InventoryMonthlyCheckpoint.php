<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores an item's completed tenant-month cutoff and included movement watermark.
 * Closing balances are derived from immutable movements; active reservations and
 * serialized identity remain live and are never frozen by this checkpoint.
 */
class InventoryMonthlyCheckpoint extends Model
{
    use BelongToTenant;

    // Permit only checkpoint metadata written by the summarizer's owning transaction.
    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'month',
        'timezone',
        'cutoff_at',
        'movement_watermark',
        'completed_at'
        ];

    // Preserve month identity and interpret cutoff/completion timestamps in application UTC.
    protected $casts = [
        'month' => 'immutable_date',
        'cutoff_at' => 'immutable_datetime',
        'movement_watermark' => 'integer',
        'completed_at' => 'immutable_datetime'
        ];
}
