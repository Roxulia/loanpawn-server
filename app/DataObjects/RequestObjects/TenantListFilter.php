<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class TenantListFilter extends BaseDataObject
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $status = null,
        public readonly ?int $typeId = null,
        public readonly ?int $accountId = null,
        public readonly ?int $lenderId = null,
        public readonly ?int $customerId = null,
        public readonly ?string $customerCode = null,
        public readonly ?string $nrcCitizen = null,
        public readonly ?string $nrcState = null,
        public readonly ?string $nrcTownship = null,
        public readonly ?string $nrcNumber = null,
        public readonly ?bool $applyInterest = null,
        public readonly ?string $fromDate = null,
        public readonly ?string $toDate = null,
    ) {}

    public static function fromValidated(array $data): self
    {
        return new self(
            search: self::stringValue($data['search'] ?? null),
            status: self::stringValue($data['status'] ?? null),
            typeId: isset($data['type_id']) ? (int) $data['type_id'] : null,
            accountId: isset($data['account_id']) ? (int) $data['account_id'] : null,
            lenderId: isset($data['lender_id']) ? (int) $data['lender_id'] : null,
            customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            customerCode: self::stringValue($data['customer_code'] ?? null),
            nrcCitizen: self::stringValue($data['nrc_citizen'] ?? null),
            nrcState: self::stringValue($data['nrc_state'] ?? null),
            nrcTownship: self::stringValue($data['nrc_township'] ?? null),
            nrcNumber: self::stringValue($data['nrc_number'] ?? null),
            applyInterest: array_key_exists('apply_interest', $data) ? (bool) $data['apply_interest'] : null,
            fromDate: self::stringValue($data['from_date'] ?? null),
            toDate: self::stringValue($data['to_date'] ?? null),
        );
    }

    private static function stringValue(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
