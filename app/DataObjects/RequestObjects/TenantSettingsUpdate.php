<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantSettingsUpdate extends BaseDataObject
{
    public function __construct(
        public ?TenantBrandingUpdate $branding = null,
        public ?TenantContactUpdate $contact = null,
        public ?TenantDefaultUserPasswordUpdate $defaultUserPassword = null,
    ) {
    }
}
