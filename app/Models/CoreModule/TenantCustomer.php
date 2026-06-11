<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantCustomer extends Model
{
    use BelongToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'nrc',
        'email',
        'phone',
        'address',
        'trust_score',
        'note',
        'is_deleted',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_deleted' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

}
