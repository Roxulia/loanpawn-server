<?php
namespace App\Models\PurchasingModule;

use App\Models\CoreModule\Currency;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierCredit extends Model
{
    use BelongToTenant;
    protected $fillable = ['tenant_id','code','supplier_id','currency_id','source_return_item_code','original_amount','remaining_amount','created_by'];
    protected $casts = ['original_amount'=>'decimal:2','remaining_amount'=>'decimal:2'];
    public function supplier(): BelongsTo { return $this->belongsTo(PurchaseSupplier::class); }
    public function currency(): BelongsTo { return $this->belongsTo(Currency::class); }
    public function allocations(): HasMany { return $this->hasMany(SupplierCreditAllocation::class); }
}