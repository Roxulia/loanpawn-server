<?php

namespace App\Models\PlatformModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseStatusLog extends Model
{
    protected $fillable = [
        'license_id',
        'old_status',
        'new_status',
        'changed_by',
        'reason',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(TenantLicense::class, 'license_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'changed_by');
    }
}
