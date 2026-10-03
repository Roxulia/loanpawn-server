<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_return_id', 'purchase_receipt_line_id', 'returned_quantity', 'inventory_unit_codes', 'supplier_credit_code', 'supplier_credit_amount', 'supplier_payable_adjusted_amount', 'cash_refund_amount', 'financial_account_id', 'accounting_transaction_id'];
    protected $casts = ['returned_quantity' => 'decimal:3', 'inventory_unit_codes' => 'array', 'supplier_credit_code' => 'string', 'supplier_credit_amount' => 'decimal:2', 'supplier_payable_adjusted_amount' => 'decimal:2', 'cash_refund_amount' => 'decimal:2'];
    public function returnRecord(): BelongsTo { return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id'); }
    public function receiptLine(): BelongsTo { return $this->belongsTo(PurchaseReceiptLine::class, 'purchase_receipt_line_id'); }
}
