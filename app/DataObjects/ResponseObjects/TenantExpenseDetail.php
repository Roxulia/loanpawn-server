<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantExpense;

class TenantExpenseDetail extends BaseDataObject
{
    public int $id;
    public int $tenantId;
    public string $code;
    public int $updateKey;
    public string $description;
    public string $amount;
    public ?int $expenseTypeId;
    public ?string $expenseTypeCode;
    public ?string $expenseTypeName;
    public ?int $createdBy;
    public ?string $creatorName;
    public bool $hasImageReference;
    public ?string $createdAt;
    public ?string $updatedAt;

    public static function fromModel(TenantExpense $expense): static
    {
        $detail = new static();
        $detail->id = $expense->id;
        $detail->tenantId = $expense->tenant_id;
        $detail->code = $expense->code;
        $detail->updateKey = (int) $expense->update_key;
        $detail->description = $expense->description;
        $detail->amount = (string) $expense->amount;
        $detail->expenseTypeId = $expense->expense_type_id;
        $detail->expenseTypeCode = $expense->expenseType?->code;
        $detail->expenseTypeName = $expense->expenseType?->name;
        $detail->createdBy = $expense->created_by;
        $detail->creatorName = $expense->creator?->name;
        $detail->hasImageReference = filled($expense->image_reference);
        $detail->createdAt = $expense->created_at?->toISOString();
        $detail->updatedAt = $expense->updated_at?->toISOString();

        return $detail;
    }
}
