<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'key',
        'value',
        'category',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'string',
        ];
    }
}
