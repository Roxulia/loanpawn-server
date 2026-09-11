<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\MaterialType;
use App\Models\CoreModule\ItemCategoryType;
use App\Traits\BelongToTenant;
use Database\Factories\PawnCollateralItemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PawnCollateralItem extends Model
{
    protected $with = ['subItems.materialType'];

    use BelongToTenant;
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): PawnCollateralItemFactory
    {
        return PawnCollateralItemFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'update_key',
        'loan_contract_id',
        'type',
        'name',
        'description',
        'brand_name',
        'image_url',
        'estimated_value',
        'material_type_id',
        'material_price_per_kyat',
        'item_category_type_id',
        'kyat',
        'pal',
        'yway',
        'item_status',
        'contains_gemstones',
        'gemstone_details',
        'quantity',
        'minimum_retail_price',
        'is_deleted',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'material_price_per_kyat' => 'decimal:2',
            'kyat' => 'decimal:2',
            'pal' => 'decimal:2',
            'yway' => 'decimal:2',
            'contains_gemstones' => 'boolean',
            'gemstone_details' => 'array',
            'minimum_retail_price' => 'decimal:2',
            'is_deleted' => 'boolean',
        ];
    }

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(MaterialType::class);
    }

    public function subItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PawnCollateralPackItem::class)->orderBy('id');
    }

    public function itemCategoryType(): BelongsTo
    {
        return $this->belongsTo(ItemCategoryType::class);
    }

    public function loanContract(): BelongsTo
    {
        return $this->belongsTo(PawnLoanContractSlip::class, 'loan_contract_id');
    }

}
