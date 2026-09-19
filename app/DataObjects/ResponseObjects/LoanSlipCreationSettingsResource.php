<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantSetting;

class LoanSlipCreationSettingsResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $updateKey,
        public bool $customerInfoRequired,
    ) {}

    public static function fromModel(TenantSetting $setting): self
    {
        $value = json_decode((string) $setting->value, true);
        $value = is_array($value) ? $value : [];

        return new self(
            id: (int) $setting->id,
            tenantId: (int) $setting->tenant_id,
            updateKey: (int) $setting->update_key,
            customerInfoRequired: (bool) ($value['customer_info_required'] ?? true),
        );
    }
}
