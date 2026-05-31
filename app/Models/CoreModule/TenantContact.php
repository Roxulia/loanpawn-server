<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantContact extends Model
{
    use BelongToTenant;

    protected $fillable = [
        'tenant_id',
        'tenant_code',
        'address',
        'phone',
        'city',
        'country',
    ];

}
