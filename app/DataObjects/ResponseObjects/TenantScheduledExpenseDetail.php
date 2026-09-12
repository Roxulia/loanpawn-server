<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantScheduledExpense;

class TenantScheduledExpenseDetail extends BaseDataObject
{
    public array $data;

    public function __construct(array $data = []) { $this->data = $data; }

    public function toArray(): array { return $this->data; }

    public static function fromModel(TenantScheduledExpense $schedule): self
    {
        return new self([
            'id' => $schedule->id,
            'code' => $schedule->code,
            'update_key' => (int) $schedule->update_key,
            'account_id' => (int) $schedule->account_id,
            'account_name' => $schedule->account?->account_name,
            'currency_code' => $schedule->account?->currency?->code,
            'currency_symbol' => $schedule->account?->currency?->symbol,
            'description' => $schedule->description,
            'amount' => (string) $schedule->amount,
            'expense_type_id' => $schedule->expense_type_id,
            'expense_type_name' => $schedule->expenseType?->name,
            'recurrence_type' => $schedule->recurrence_type,
            'start_date' => $schedule->start_date?->toDateString(),
            'scheduled_time' => substr((string) $schedule->scheduled_time, 0, 5),
            'end_date' => $schedule->end_date?->toDateString(),
            'weekly_day' => $schedule->weekly_day === null ? null : (int) $schedule->weekly_day,
            'monthly_anchor_day' => $schedule->monthly_anchor_day === null ? null : (int) $schedule->monthly_anchor_day,
            'status' => $schedule->status,
            'next_due_date' => $schedule->next_due_date?->toDateString(),
            'last_due_date' => $schedule->last_due_date?->toDateString(),
            'last_paid_at' => $schedule->last_paid_at?->toISOString(),
            'last_result' => $schedule->last_result,
            'last_error' => $schedule->last_error,
            'pending_count' => (int) ($schedule->pending_count ?? 0),
            'created_by' => $schedule->created_by,
            'creator_name' => $schedule->creator?->name,
            'created_at' => $schedule->created_at?->toISOString(),
            'updated_at' => $schedule->updated_at?->toISOString(),
        ]);
    }
}
