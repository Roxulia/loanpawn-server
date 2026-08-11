<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\FinancialAccountTypes;

class FinancialAccountTypeResource extends BaseDataObject
{
    public function __construct(
        public int $id,
        public ?int $tenantId,
        public string $code,
        public string $name,
        public bool $isActive,
        public int $updateKey,
        public string $source,
        public bool $canUpdate,
        public bool $canDelete,
    ) {}

    public static function fromModel(FinancialAccountTypes $type): self
    {
        $isTenantOwned = $type->tenant_id !== null;

        return new self(
            id: $type->id,
            tenantId: $type->tenant_id,
            code: $type->code,
            name: $type->name,
            isActive: $type->is_active,
            updateKey: $type->update_key,
            source: $isTenantOwned ? 'TENANT' : 'PLATFORM',
            canUpdate: $isTenantOwned,
            canDelete: $isTenantOwned,
        );
    }
}
