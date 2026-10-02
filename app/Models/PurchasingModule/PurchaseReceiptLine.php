<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptLine extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_receipt_id', 'purchase_order_line_id', 'received_quantity'];
    protected $casts = ['received_quantity' => 'decimal:3'];
    public function receipt(): BelongsTo { return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id'); }
    public function orderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id'); }
}
