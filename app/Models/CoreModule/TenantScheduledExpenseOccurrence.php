<?php

namespace App\Models\CoreModule;

use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\FinancialAccount;

class TenantScheduledExpenseOccurrence extends Model
{
    use BelongToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'executed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function schedule(): BelongsTo { return $this->belongsTo(TenantScheduledExpense::class, 'scheduled_expense_id'); }
    public function expense(): BelongsTo { return $this->belongsTo(TenantExpense::class, 'expense_id'); }
    public function account(): BelongsTo { return $this->belongsTo(FinancialAccount::class, 'account_id'); }
    public function expenseType(): BelongsTo { return $this->belongsTo(ExpenseType::class); }
}
