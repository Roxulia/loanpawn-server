<?php

namespace App\Models;

use App\Enums\FinancialAccountTransactionType;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccountTransaction extends Model
{
    protected $fillable = [
        'tenant_id',
        'financial_account_id',
        'transaction_type',
        'amount',
        'direction',
        'reference_number',
        'reference_type',
        'note',
        'created_by',
        'related_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_type' => FinancialAccountTransactionType::class,
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }

    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(TenantAccountingTransactions::class, 'related_transaction_id');
    }
}
