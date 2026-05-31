<?php

namespace App\Models\CoreModule;

use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InterestType extends Model
{
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'duration_in_days',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
