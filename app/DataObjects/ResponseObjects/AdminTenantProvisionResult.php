<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PlatformModule\Tenant;

class AdminTenantProvisionResult extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public string $licenseKey,
        public string $expiresAt,
    ) {}

    public static function fromModel(Tenant $tenant): self
    {
        return new self(
            id: (int) $tenant->id,
            name: $tenant->name,
            code: $tenant->tenant_code,
            licenseKey: $tenant->license->license_key,
            expiresAt: $tenant->license->expires_at->toDateString(),
        );
    }
}
