<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantSetting;

class InterestProcessSettingsResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $updateKey,
        public bool $compoundingEnabled,
        public bool $partialPrincipalCollectionEnabled,
    ) {}

    public static function fromModel(TenantSetting $setting): self
    {
        $value = json_decode((string) $setting->value, true);
        $value = is_array($value) ? $value : [];

        return new self(
            id: (int) $setting->id,
            tenantId: (int) $setting->tenant_id,
            updateKey: (int) $setting->update_key,
            compoundingEnabled: (bool) ($value['compounding_enabled'] ?? false),
            partialPrincipalCollectionEnabled: (bool) ($value['partial_principal_collection_enabled'] ?? false),
        );
    }
}
