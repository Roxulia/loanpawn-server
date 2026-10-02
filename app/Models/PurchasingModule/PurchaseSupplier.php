<?php

namespace App\Models\PurchasingModule;

use App\Traits\BelongToTenant;
use App\Models\CoreModule\TenantPerson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseSupplier extends Model
{
    use BelongToTenant;

    protected $fillable = ['tenant_id', 'code', 'person_id', 'type', 'contact_name', 'note', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function orders(): HasMany { return $this->hasMany(PurchaseOrder::class, 'supplier_id'); }
    public function person(): BelongsTo { return $this->belongsTo(TenantPerson::class, 'person_id'); }
}
