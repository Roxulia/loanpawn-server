<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class PurchaseOrder extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'supplier_id', 'status', 'ordered_at', 'currency_code', 'note', 'created_by'];
    protected $casts = ['ordered_at' => 'date:Y-m-d'];

    public function supplier(): BelongsTo { return $this->belongsTo(PurchaseSupplier::class, 'supplier_id'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseOrderLine::class); }
    public function payments(): HasMany { return $this->hasMany(PurchasePayment::class); }
    public function receipts(): HasMany { return $this->hasMany(PurchaseReceipt::class); }
    public function supplierPayables(): HasMany { return $this->hasMany(SupplierPayable::class, 'purchase_order_id'); }
    public function returns(): HasMany { return $this->hasMany(PurchaseReturn::class); }
}
