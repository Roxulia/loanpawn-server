<?php

namespace App\DataObjects\ResponseObjects;

use App\Models\CoreModule\TenantCustomer;

class TenantCustomerListItem extends TenantCustomerDetail
{
    public int $displayTrustScore;
    public int $activeSlipCount;
    public string $primaryLocation;
    public TenantCustomerLastActivity $lastActivity;

    public static function fromModelWithActivity(
        TenantCustomer $customer,
        TenantCustomerLastActivity $lastActivity,
    ): self {
        $detail = self::fromModel($customer);
        $detail->displayTrustScore = self::normalizeTrustScore((int) $customer->trust_score);
        $detail->activeSlipCount = (int) ($customer->active_slip_count ?? 0);
        $detail->primaryLocation = trim((string) $customer->address) !== '' ? (string) $customer->address : '-';
        $detail->lastActivity = $lastActivity;

        return $detail;
    }

    public static function normalizeTrustScore(int $trustScore): int
    {
        return max(0, min(100, (int) round(($trustScore / 255) * 100)));
    }
}
