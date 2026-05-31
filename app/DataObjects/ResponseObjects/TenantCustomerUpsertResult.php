<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantCustomerUpsertResult extends BaseDataObject
{
    public bool $created;
    public TenantCustomerDetail $customer;

    public static function created(TenantCustomerDetail $customer): self
    {
        $result = new self();
        $result->created = true;
        $result->customer = $customer;

        return $result;
    }

    public static function existing(TenantCustomerDetail $customer): self
    {
        $result = new self();
        $result->created = false;
        $result->customer = $customer;

        return $result;
    }
}
