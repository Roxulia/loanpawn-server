<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class TenantRequestPaymentSubmit extends BaseDataObject
{
    public function __construct(
        public int $tenantRequestId,
        public int $updateKey,
        public UploadedFile $paymentScreenshot,
        public ?string $paymentReference = null,
        public ?string $note = null,
    ) {
    }
}
