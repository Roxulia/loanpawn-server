<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRefund extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_payment_id', 'refunded_at', 'amount', 'reference', 'note', 'created_by'];
    protected $casts = ['refunded_at' => 'date:Y-m-d', 'amount' => 'decimal:2'];
    public function payment(): BelongsTo { return $this->belongsTo(PurchasePayment::class, 'purchase_payment_id'); }
}
