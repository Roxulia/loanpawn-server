<?php

namespace App\Models\CoreModule;

use App\Models\PawnModule\PawnLoanContractSlip;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantCustomer extends Model
{
    use BelongToTenant;
    use SoftDeletes;

    public const MAX_TRUST_SCORE = 255;
    public const DEFAULT_TRUST_SCORE = 128;

    protected $attributes = [
        'trust_score' => self::DEFAULT_TRUST_SCORE,
    ];

    protected $fillable = [
        'tenant_id',
        'code',
        'update_key',
        'name',
        'nrc',
        'email',
        'phone',
        'address',
        'trust_score',
        'note',
        'is_deleted',
        'is_auto_generated',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
            'is_auto_generated' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function pawnSlips(): HasMany
    {
        return $this->hasMany(PawnLoanContractSlip::class, 'customer_id');
    }

}
