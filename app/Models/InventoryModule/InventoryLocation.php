<?php

namespace App\Models\InventoryModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class InventoryLocation extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'name', 'type', 'is_default', 'is_active', 'is_sellable'];

    protected $casts = ['is_default' => 'boolean', 'is_active' => 'boolean', 'is_sellable' => 'boolean'];
}
