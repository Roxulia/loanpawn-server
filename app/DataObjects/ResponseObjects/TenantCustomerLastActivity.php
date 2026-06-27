<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantCustomerLastActivity extends BaseDataObject
{
    public ?string $date;
    public string $status;
    public string $label;
    public string $tone;

    public function __construct(
        ?string $date,
        string $status,
        string $label,
        string $tone,
    ) {
        $this->date = $date;
        $this->status = $status;
        $this->label = $label;
        $this->tone = $tone;
    }
}
