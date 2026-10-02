<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'purchase_order_id', 'returned_at', 'reason', 'note', 'created_by'];
    protected $casts = ['returned_at' => 'date:Y-m-d'];
    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseReturnLine::class); }
}
