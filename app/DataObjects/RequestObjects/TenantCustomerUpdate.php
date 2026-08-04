<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantCustomerUpdate extends BaseDataObject
{
    public function __construct(
        public int $customerId,
        public string $code,
        public int $updateKey,
        public ?string $name = null,
        public ?string $nrc = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $address = null,
        public ?int $trustScore = null,
        public ?string $note = null,
        public array $providedFields = [],
    ) {
        if ($this->providedFields === []) {
            $this->providedFields = array_keys(array_filter([
                'name' => $this->name,
                'nrc' => $this->nrc,
                'email' => $this->email,
                'phone' => $this->phone,
                'address' => $this->address,
                'trustScore' => $this->trustScore,
                'note' => $this->note,
            ], fn (mixed $value): bool => $value !== null));
        }
    }

    public function isProvided(string $field): bool
    {
        return in_array($field, $this->providedFields, true);
    }
}
