<?php

namespace App\Models\CoreModule;

use App\Models\FinancialAccount;
use App\Traits\BelongToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantScheduledExpense extends Model
{
    use BelongToTenant, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_due_date' => 'date',
            'last_due_date' => 'date',
            'last_paid_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo { return $this->belongsTo(FinancialAccount::class, 'account_id'); }
    public function expenseType(): BelongsTo { return $this->belongsTo(ExpenseType::class); }
    public function creator(): BelongsTo { return $this->belongsTo(TenantUser::class, 'created_by'); }
    public function occurrences(): HasMany { return $this->hasMany(TenantScheduledExpenseOccurrence::class, 'scheduled_expense_id'); }
}
