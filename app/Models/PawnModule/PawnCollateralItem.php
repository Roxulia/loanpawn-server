<?php

namespace App\Models\PawnModule;

use App\Models\CoreModule\MaterialType;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PawnCollateralItem extends Model
{
    use BelongToTenant;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'loan_contract_id',
        'type',
        'name',
        'description',
        'brand_name',
        'image_url',
        'estimated_value',
        'material_type_id',
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

    public function loanContract(): BelongsTo
    {
        return $this->belongsTo(PawnLoanContractSlip::class, 'loan_contract_id');
    }

}
