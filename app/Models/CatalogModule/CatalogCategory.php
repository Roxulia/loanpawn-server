<?php

namespace App\Models\CatalogModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;

class CatalogCategory extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'name', 'is_active', 'update_key'];
    protected $casts = ['is_active' => 'boolean', 'update_key' => 'integer'];
}
