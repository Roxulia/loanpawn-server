<?php

namespace App\Models\CoreModule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRateCorrection extends Model
{
    protected $fillable = ['original_entry_id', 'replacement_entry_id', 'tenant_id', 'scope_key', 'action', 'reason', 'corrected_by_tenant_user_id', 'corrected_by_platform_admin_id'];

    public function originalEntry(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateEntry::class, 'original_entry_id');
    }

    public function replacementEntry(): BelongsTo
    {
        return $this->belongsTo(ExchangeRateEntry::class, 'replacement_entry_id');
    }
}
