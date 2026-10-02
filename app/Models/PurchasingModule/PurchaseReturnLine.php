<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_return_id', 'purchase_receipt_line_id', 'returned_quantity'];
    protected $casts = ['returned_quantity' => 'decimal:3'];
    public function returnRecord(): BelongsTo { return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id'); }
    public function receiptLine(): BelongsTo { return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id'); }
}
