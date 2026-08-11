<?php

namespace App\DataObjects\ResponseObjects;

use App\Models\CoreModule\TenantExpense;

class TenantExpenseFullDetail extends TenantExpenseDetail
{
    public ?string $imageReferenceUrl;
    public ?string $imageReferenceUrlExpiresAt;

    public static function fromModelWithImage(
        TenantExpense $expense,
        ?string $imageReferenceUrl,
        ?string $expiresAt,
    ): self {
        $detail = self::fromModel($expense);
        $detail->imageReferenceUrl = $imageReferenceUrl;
        $detail->imageReferenceUrlExpiresAt = $expiresAt;

        return $detail;
    }
}
