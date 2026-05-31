<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\CoreModule\TenantContact;

class TenantContactDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public int $id;
    public int $tenantId;
    public int $updateKey;
    public ?string $address;
    public ?string $phone;
    public ?string $city;
    public ?string $country;

    public function __construct()
    {
        //
    }

    public static function fromModel(TenantContact $contact): self
    {
        $detail = new self();
        $detail->id = $contact->id;
        $detail->tenantId = $contact->tenant_id;
        $detail->updateKey = (int) $contact->update_key;
        $detail->address = $contact->address;
        $detail->phone = $contact->phone;
        $detail->city = $contact->city;
        $detail->country = $contact->country;

        return $detail;
    }
}
