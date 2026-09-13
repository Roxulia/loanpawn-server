<?php

namespace App\Models\CoreModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Database\Factories\TenantExpenseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantExpense extends Model
{
    use BelongToTenant;
    use HasFactory;

    protected static function newFactory(): TenantExpenseFactory
    {
        return TenantExpenseFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'account_id',
        'description',
        'amount',
        'expense_type_id',
        'image_reference',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'created_by');
    }
}
