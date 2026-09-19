<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\MaterialType;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PawnCollateralPackItem extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'name', 'quantity', 'kyat', 'pal', 'yway', 'material_type_id', 'image_url'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'kyat' => 'decimal:2', 'pal' => 'decimal:2', 'yway' => 'decimal:2'];
    }

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }
}
