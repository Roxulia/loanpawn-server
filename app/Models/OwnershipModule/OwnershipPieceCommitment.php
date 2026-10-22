<?php

namespace App\Models\OwnershipModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a serialized membership committed to one sale reservation or pledge.
 * Connects an exact piece's ownership period to its quantity commitment.
 * An open row holds the piece for that commitment; ended_at preserves the released
 * or consumed commitment as history.
 * A database-generated unique current_membership_id prevents two open commitments
 * for the same membership. Services coordinate it with quantity and membership changes.
 */
class OwnershipPieceCommitment extends Model
{
    use BelongToTenant;

    // Only stored business fields may be assigned; generated current-membership keys stay database-owned.
    protected $fillable = ['tenant_id', 'ownership_commitment_id', 'ownership_piece_membership_id', 'ended_at'];

    // Preserve decimal strings and immutable business dates across model hydration.
    protected $casts = [
        'ended_at' => 'immutable_datetime',
    ];

    public function commitment(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(OwnershipCommitment::class, 'ownership_commitment_id');
    }

    public function membership(): BelongsTo
    {
        // Resolve the stored reference within the existing tenant model conventions.
        return $this->belongsTo(OwnershipPieceMembership::class, 'ownership_piece_membership_id');
    }
}
