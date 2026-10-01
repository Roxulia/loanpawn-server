<?php

namespace App\Models\CatalogModule;

use Illuminate\Database\Eloquent\Model;

class CatalogUnit extends Model
{
    protected $table = 'catalog_units';
    protected $fillable = ['tenant_id', 'scope_key', 'code', 'name', 'symbol', 'is_active', 'is_system', 'created_by_platform_admin_id'];
    protected $casts = ['is_active' => 'boolean', 'is_system' => 'boolean'];
}
