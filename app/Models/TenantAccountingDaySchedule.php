<?php

namespace App\Models;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantAccountingDaySchedule extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'weekday',
        'is_enabled',
        'open_time',
        'close_time',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_enabled' => 'boolean',
            'update_key' => 'integer',
        ];
    }
}
