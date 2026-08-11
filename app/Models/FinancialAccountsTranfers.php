<?php

namespace App\Models;

use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccountsTranfers extends Model
{
    protected $table = 'financial_account_transfers';

    protected $fillable = [
        'tenant_id',
        'from_account_id',
        'to_account_id',
        'from_currency_id',
        'to_currency_id',
        'from_amount',
        'to_amount',
        'exchange_rate',
        'exchange_rate_source',
        'fee_amount',
        'fee_account_id',
        'note',
        'transferred_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'from_amount' => 'decimal:4',
            'to_amount' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'fee_amount' => 'decimal:4',
            'transferred_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency_id');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency_id');
    }

    public function feeAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'fee_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }
}
