<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchasePayment extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_order_id', 'paid_at', 'amount', 'financial_account_id', 'accounting_transaction_id', 'reference', 'note', 'created_by'];
    protected $casts = ['paid_at' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function refunds(): HasMany { return $this->hasMany(PurchaseRefund::class); }
}
