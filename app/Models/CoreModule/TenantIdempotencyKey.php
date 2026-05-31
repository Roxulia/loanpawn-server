<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantIdempotencyKey extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'operation',
        'idempotency_key',
        'request_hash',
        'status',
        'response_code',
        'response_body',
        'resource_type',
        'resource_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
