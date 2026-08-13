<?php

namespace App\Models;

use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccountTypes extends Model
{
    protected $table = 'financial_account_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'is_active',
        'update_key',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'update_key' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(FinancialAccount::class, 'account_type_id');
    }
}
