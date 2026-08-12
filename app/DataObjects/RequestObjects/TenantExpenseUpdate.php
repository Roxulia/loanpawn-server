<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class TenantExpenseUpdate extends BaseDataObject
{
    public function __construct(
        public int $expenseId,
        public string $code,
        public int $updateKey,
        public int $accountId,
        public ?string $description = null,
        public ?int $expenseTypeId = null,
        public bool $hasExpenseTypeId = false,
        public ?UploadedFile $imageReference = null,
        public bool $removeImageReference = false,
    ) {}
}
