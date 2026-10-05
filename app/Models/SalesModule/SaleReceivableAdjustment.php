<?php

namespace App\Models\SalesModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReceivableAdjustment extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'sale_receivable_id', 'adjustment_type', 'amount', 'source_type', 'source_code', 'accounting_transaction_id', 'created_by'];
    protected $casts = ['amount' => 'decimal:2'];

    // Link each receivable adjustment to its customer balance.
    public function receivable(): BelongsTo { return $this->belongsTo(SaleReceivable::class, 'sale_receivable_id'); }
}
