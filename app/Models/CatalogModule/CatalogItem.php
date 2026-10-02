<?php

namespace App\Models\CatalogModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogItem extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'business_code', 'name', 'description', 'category_id', 'sku', 'barcode', 'tracking_mode', 'unit_id', 'is_active', 'update_key'];
    protected $casts = ['is_active' => 'boolean', 'update_key' => 'integer'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(CatalogUnit::class, 'unit_id');
    }
}
