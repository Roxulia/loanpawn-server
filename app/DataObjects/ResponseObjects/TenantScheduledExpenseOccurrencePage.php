<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class TenantScheduledExpenseOccurrencePage extends BaseDataObject
{
    public function __construct(public array $data = []) {}

    public function toArray(): array { return $this->data; }

    public static function fromPaginator(LengthAwarePaginator $paginator): self
    {
        return new self([
            'items' => array_map(fn ($item): array => [
                'id' => (int) $item->id,
                'due_date' => $item->due_date?->toDateString(),
                'due_time' => substr((string) $item->due_time, 0, 5),
                'description' => $item->description,
                'amount' => (string) $item->amount,
                'account_id' => (int) $item->account_id,
                'account_name' => $item->account?->account_name,
                'currency_code' => $item->account?->currency?->code,
                'currency_symbol' => $item->account?->currency?->symbol,
                'expense_type_name' => $item->expenseType?->name,
                'expense_code' => $item->expense?->code,
                'status' => $item->status,
                'attempt_count' => (int) $item->attempt_count,
                'last_error' => $item->last_error,
                'executed_at' => $item->executed_at?->toISOString(),
                'cancelled_at' => $item->cancelled_at?->toISOString(),
            ], $paginator->items()),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }
}
