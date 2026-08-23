<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use App\Models\PlatformModule\TenantLicense;

class TenantLicenseDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public string $licenseKey;
    public int $updateKey;
    public string $planType;
    public ?int $planId = null;
    public ?string $planCode = null;
    public ?string $planName = null;
    public ?int $planRank = null;
    public ?string $expiresAt;
    public string $status;
    public int $currentMonthSlipCount = 0;
    public int $currentStaffCount = 0;
    public int $currentAccountCount = 0;
    public int $currentCurrencyTypeCount = 0;
    public int $currentExchangePairCount = 0;
    public ?int $maxSlipPerMonth = null;
    public ?int $maxStaffCount = null;
    public ?int $maxAccountCount = null;
    public ?int $maxCurrencyTypeCount = null;
    public ?int $maxExchangePairCount = null;
    public ?string $nextPlanType = null;
    public ?string $nextPlanStartsAt = null;
    public ?string $nextPlanExpiresAt = null;

    public function __construct()
    {
        //
    }

    public static function fromModel(TenantLicense $license): self
    {
        $detail = new self();
        $detail->licenseKey = $license->license_key;
        $detail->updateKey = (int) $license->update_key;
        $plan = $license->plan;
        $detail->planId = $plan?->id;
        $detail->planCode = $plan?->code ?? $license->plan_type;
        $detail->planName = $plan?->name;
        $detail->planRank = $plan?->rank;
        $detail->planType = $detail->planCode;
        $detail->expiresAt = $license->expires_at?->toISOString();
        $detail->status = $license->status;
        $detail->currentMonthSlipCount = (int) $license->current_month_slip_count;
        $detail->currentStaffCount = (int) $license->current_staff_count;
        $detail->currentAccountCount = (int) $license->current_account_count;
        $detail->currentCurrencyTypeCount = (int) $license->current_currency_type_count;
        $detail->currentExchangePairCount = (int) $license->current_exchange_pair_count;
        $detail->maxSlipPerMonth = $plan?->max_slip_per_month;
        $detail->maxStaffCount = $plan?->max_staff_count;
        $detail->maxAccountCount = $plan?->max_account_count;
        $detail->maxCurrencyTypeCount = $plan?->max_currency_type_count;
        $detail->maxExchangePairCount = $plan?->max_exchange_pair_count;
        $transition = $license->scheduledPlanTransition;
        $detail->nextPlanType = $transition?->toPlan?->code ?? $transition?->to_plan_type;
        $detail->nextPlanStartsAt = $transition?->starts_at?->toISOString();
        $detail->nextPlanExpiresAt = $transition?->expires_at?->toISOString();

        return $detail;
    }
}
