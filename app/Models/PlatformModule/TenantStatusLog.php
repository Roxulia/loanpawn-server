<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantStatusLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'old_status',
        'new_status',
        'changed_by',
        'reason',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'changed_by');
    }
}
