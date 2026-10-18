<?php

namespace App\Models\InventoryModule;

use App\Database\InventoryMovementQueryBuilder;
use App\Exceptions\InvalidTenantRequest;
use Illuminate\Database\Eloquent\Builder;
use App\Traits\BelongToTenant;
use App\Models\CoreModule\TenantUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a tenant's append-only physical stock movement and its provenance.
 * Location, serialized-item, and actor relations support batched history reads.
 * Model and query guards reject history mutations; services own transaction boundaries.
 */
class InventoryMovement extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id', 'code', 'inventory_item_id', 'inventory_serialized_item_id', 'from_location_id', 'to_location_id',
        'quantity', 'movement_type', 'reason', 'source_type', 'source_code', 'idempotency_record_id',
        'created_by', 'occurred_at',
    ];

    protected $casts = ['quantity' => 'decimal:3', 'occurred_at' => 'immutable_datetime'];


    /** Use an append-only query builder even for bulk/quiet model operations. */
    protected function newBaseQueryBuilder()
    {
        // Preserve this model's connection, grammar, and result processor.
        $connection = $this->getConnection();

        return new InventoryMovementQueryBuilder(
            $connection,
            $connection->getQueryGrammar(),
            $connection->getPostProcessor(),
        );
    }

    /** Reject persisted-model rewrites, including saveQuietly. */
    protected function performUpdate(Builder $query)
    {
        // Model events alone do not protect quiet update paths.
        throw new InvalidTenantRequest(__('inventory.movement_immutable'));
    }

    /** Reject instance deletion, including quiet/force deletion. */
    public function delete()
    {
        // Source movements are corrected by appending an inverse/corrective movement.
        throw new InvalidTenantRequest(__('inventory.movement_immutable'));
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }

    public function serializedItem(): BelongsTo
    {
        // Allow paginated history to eager-load serialized identity provenance in one batch.
        return $this->belongsTo(InventorySerializedItem::class, 'inventory_serialized_item_id');
    }

    public function createdBy(): BelongsTo
    {
        // Resolve the tenant-scoped actor for history without one query per movement.
        return $this->belongsTo(TenantUser::class, 'created_by');
    }
}
