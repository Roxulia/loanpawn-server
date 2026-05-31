<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantCreate extends BaseDataObject
{
    /**
     * Create a new class instance.
     */

    public string $name;
    public ?string $subdomain;
    public ?string $code;
    public bool $createdByAdmin;
    public ?int $platformUserId;
    public ?string $planType;
    public ?string $status;
    public ?string $expireAt;
    public ?string $notes;
    public ?string $address;
    public ?string $phone;
    public ?string $city;
    public ?string $country;

    public function __construct(
        string $name,
        ?string $code,
        ?string $subdomain,
        bool $createdByAdmin,
        ?string $planType,
        ?string $status = null,
        ?int $platformUserId = null,
        ?string $expireAt = null,
        ?string $notes = null,
        ?string $address = null,
        ?string $phone = null,
        ?string $city = null,
        ?string $country = null,
    )
    {
        $this->name = $name;
        $this->code = $code;
        $this->subdomain = $subdomain;
        $this->createdByAdmin = $createdByAdmin;
        $this->platformUserId = $platformUserId;
        $this->planType = (!$createdByAdmin) ? "trial" : $planType;
        $this->status = (!$createdByAdmin) ? "active" : $status;
        $this->expireAt = $expireAt;
        $this->notes = $notes;
        $this->address = $address;
        $this->phone = $phone;
        $this->city = $city;
        $this->country = $country;
    }
}
