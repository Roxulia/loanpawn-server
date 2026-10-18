<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Represents a tenant's physical stock location.
 * The integer update_key supports optimistic concurrency checks on location edits.
 */
class InventoryLocation extends Model
{
    use BelongToTenant;

    // Allow repository writes to persist the location's concurrency version.
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'is_default',
        'is_active',
        'is_sellable',
        'update_key'
        ];

    // Expose flags as booleans and the concurrency version as an integer.
    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'is_sellable' => 'boolean',
        'update_key' => 'integer'
        ];
}
