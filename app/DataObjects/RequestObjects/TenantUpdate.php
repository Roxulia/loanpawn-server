<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;
use Illuminate\Http\UploadedFile;

class TenantUpdate extends BaseDataObject
{
    public function __construct(
        public int $tenantId,
        public int $updateKey,
        public ?string $name = null,
        public ?string $subdomain = null,
        public ?string $code = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $city = null,
        public ?string $country = null,
        public ?UploadedFile $logoFile = null,
        public ?UploadedFile $faviconFile = null,
        public ?string $logoPath = null,
        public ?string $faviconPath = null,
        public ?string $primaryColor = null,
        public ?string $secondaryColor = null,
        public ?string $accentColor = null,
    ) {
    }
}
