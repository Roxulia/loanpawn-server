<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantSetting;

class TenantDebtPaymentPolicy extends BaseDataObject
{
    public function __construct(
        public bool $allowPartialPayments,
        public int $updateKey,
    ) {}

    public static function fromModel(TenantSetting $setting): self
    {
        return new self(
            allowPartialPayments: filter_var($setting->value, FILTER_VALIDATE_BOOL),
            updateKey: (int) $setting->update_key,
        );
    }
}
