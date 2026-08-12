<?php

namespace App\Models;

use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    protected $fillable = [
        'tenant_id',
        'account_type_id',
        'update_key',
        'currency_id',
        'account_number',
        'account_name',
        'account_code',
        'balance',
        'is_active',
        'is_default',
        'is_deleted',
        'allow_negative_balance',
        'deleted_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'update_key' => 'integer',
            'balance' => 'decimal:4',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_deleted' => 'boolean',
            'allow_negative_balance' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function accountType(): BelongsTo
    {
        return $this->belongsTo(FinancialAccountTypes::class, 'account_type_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'deleted_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialAccountTransaction::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(FinancialAccountAssignment::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(FinancialAccountsTranfers::class, 'from_account_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(FinancialAccountsTranfers::class, 'to_account_id');
    }

    public function feeTransfers(): HasMany
    {
        return $this->hasMany(FinancialAccountsTranfers::class, 'fee_account_id');
    }
}
