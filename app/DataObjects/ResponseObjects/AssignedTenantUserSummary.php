<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantUser;

class AssignedTenantUserSummary extends BaseDataObject
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $roleName,
        public string $status,
    ) {}

    public static function fromModel(TenantUser $user): self
    {
        return new self(
            id: $user->id,
            code: $user->code,
            name: $user->name,
            roleName: $user->role?->name,
            status: $user->status,
        );
    }
}
