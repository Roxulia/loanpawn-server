<?php

namespace App\Repository;

use App\DataObjects\ResponseObjects\TenantBrandingDetail;
use App\DataObjects\ResponseObjects\TenantContactDetail;
use App\DataObjects\ResponseObjects\TenantDetail;
use App\DataObjects\ResponseObjects\TenantFeatures;
use App\DataObjects\ResponseObjects\TenantLicenseDetail;
use App\DataObjects\ResponseObjects\TenantSettingDetail;
use App\DataObjects\ResponseObjects\TenantSettingItem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class TenantDetailRepository
{
    public function findByTenantId(int $tenantId): ?TenantDetail
    {
        $rows = $this->baseQuery()
            ->where('tenants.id', $tenantId)
            ->get();

        return $this->mapTenantDetail($rows);
    }

    public function findBySubdomain(string $subdomain): ?TenantDetail
    {
        $rows = $this->baseQuery()
            ->where('tenants.subdomain', $subdomain)
            ->get();

        return $this->mapTenantDetail($rows);
    }

    public function findByTenantCode(string $tenantCode): ?TenantDetail
    {
        $rows = $this->baseQuery()
            ->where('tenants.tenant_code', $tenantCode)
            ->get();

        return $this->mapTenantDetail($rows);
    }

    protected function baseQuery(): Builder
    {
        return DB::table('tenants')
            ->leftJoin('tenant_licenses', 'tenant_licenses.tenant_id', '=', 'tenants.id')
            ->leftJoin('packages', 'packages.id', '=', 'tenant_licenses.plan_id')
            ->leftJoin('tenant_contacts', 'tenant_contacts.tenant_id', '=', 'tenants.id')
            ->leftJoin('tenant_branding', 'tenant_branding.tenant_id', '=', 'tenants.id')
            ->leftJoin('tenant_settings', 'tenant_settings.tenant_id', '=', 'tenants.id')
            ->leftJoin('currencies as default_currencies', 'default_currencies.id', '=', 'tenant_settings.default_currency_id')
            ->leftJoin('currencies as reporting_currencies', 'reporting_currencies.id', '=', 'tenant_settings.reporting_currency_id')
            ->leftJoin('reporting_currency_recalculations as currency_recalculations', function ($join): void {
                $join->on('currency_recalculations.tenant_id', '=', 'tenants.id')
                    ->whereIn('currency_recalculations.status', ['queued', 'processing', 'waiting_for_rates', 'failed']);
            })
            ->leftJoin('currencies as effective_reporting_currencies', 'effective_reporting_currencies.id', '=', 'currency_recalculations.previous_reporting_currency_id')
            ->select([
                'tenants.id as tenant_id',
                'tenants.name as tenant_name',
                'tenants.subdomain as tenant_subdomain',
                'tenants.tenant_code as tenant_code',
                'tenants.update_key as tenant_update_key',
                'tenant_contacts.id as contact_id',
                'tenant_contacts.update_key as contact_update_key',
                'tenant_contacts.address as contact_address',
                'tenant_contacts.phone as contact_phone',
                'tenant_contacts.city as contact_city',
                'tenant_contacts.country as contact_country',
                'tenant_licenses.id as license_id',
                'tenant_licenses.update_key as license_update_key',
                'tenant_licenses.license_key as license_key',
                'tenant_licenses.plan_id as license_plan_id',
                'tenant_licenses.plan_type as license_plan_type',
                'tenant_licenses.expires_at as license_expires_at',
                'tenant_licenses.status as license_status',
                'tenant_licenses.current_month_slip_count as license_current_month_slip_count',
                'tenant_licenses.current_staff_count as license_current_staff_count',
                'tenant_licenses.current_account_count as license_current_account_count',
                'tenant_licenses.current_currency_type_count as license_current_currency_type_count',
                'tenant_licenses.current_exchange_pair_count as license_current_exchange_pair_count',
                'packages.code as license_plan_code',
                'packages.name as license_plan_name',
                'packages.rank as license_plan_rank',
                'packages.max_slip_per_month as license_max_slip_per_month',
                'packages.max_staff_count as license_max_staff_count',
                'packages.max_account_count as license_max_account_count',
                'packages.max_currency_type_count as license_max_currency_type_count',
                'packages.max_exchange_pair_count as license_max_exchange_pair_count',
                'tenant_branding.id as branding_id',
                'tenant_branding.update_key as branding_update_key',
                'tenant_branding.logo_path as branding_logo_path',
                'tenant_branding.favicon_path as branding_favicon_path',
                'tenant_branding.primary_color as branding_primary_color',
                'tenant_branding.secondary_color as branding_secondary_color',
                'tenant_branding.accent_color as branding_accent_color',
                'tenant_branding.slip_header_layout as branding_slip_header_layout',
                'tenant_branding.slip_footer_layout as branding_slip_footer_layout',
                'tenant_settings.id as setting_id',
                'tenant_settings.update_key as setting_update_key',
                'tenant_settings.key as setting_key',
                'tenant_settings.value as setting_value',
                'tenant_settings.category as setting_category',
                'tenant_settings.default_currency_id as setting_default_currency_id',
                'tenant_settings.reporting_currency_id as setting_reporting_currency_id',
                'default_currencies.symbol as setting_default_currency_symbol',
                'reporting_currencies.symbol as setting_reporting_currency_symbol',
                'currency_recalculations.id as currency_recalculation_id',
                'currency_recalculations.status as currency_recalculation_status',
                'currency_recalculations.previous_reporting_currency_id as effective_reporting_currency_id',
                'currency_recalculations.window_start as currency_recalculation_window_start',
                'currency_recalculations.window_end as currency_recalculation_window_end',
                'currency_recalculations.missing_rates as currency_recalculation_missing_rates',
                'effective_reporting_currencies.symbol as effective_reporting_currency_symbol',
            ]);
    }

    protected function mapTenantDetail(Collection $rows): ?TenantDetail
    {
        /** @var ?stdClass $row */
        $row = $rows->first();

        if ($row === null || $row->contact_id === null || $row->license_id === null) {
            return null;
        }

        $detail = new TenantDetail();
        $detail->name = $row->tenant_name;
        $detail->subdomain = $row->tenant_subdomain;
        $detail->code = $row->tenant_code;
        $detail->updateKey = (int) $row->tenant_update_key;
        $detail->tenant_contact = $this->makeTenantContactDetail($row);
        $detail->tenant_license = $this->makeTenantLicenseDetail($row);
        $detail->tenant_features = new TenantFeatures();
        $detail->tenant_branding = $this->makeTenantBrandingDetail($row);
        $detail->tenant_setting = $this->makeTenantSettingDetail($rows);

        return $detail;
    }

    protected function makeTenantContactDetail(stdClass $row): TenantContactDetail
    {
        $detail = new TenantContactDetail();
        $detail->id = (int) $row->contact_id;
        $detail->tenantId = (int) $row->tenant_id;
        $detail->updateKey = (int) $row->contact_update_key;
        $detail->address = $row->contact_address;
        $detail->phone = $row->contact_phone;
        $detail->city = $row->contact_city;
        $detail->country = $row->contact_country;

        return $detail;
    }

    protected function makeTenantLicenseDetail(stdClass $row): TenantLicenseDetail
    {
        $detail = new TenantLicenseDetail();
        $detail->licenseKey = $row->license_key;
        $detail->updateKey = (int) $row->license_update_key;
        $detail->planId = $row->license_plan_id === null ? null : (int) $row->license_plan_id;
        $detail->planCode = $row->license_plan_code ?? $row->license_plan_type;
        $detail->planName = $row->license_plan_name;
        $detail->planRank = $row->license_plan_rank === null ? null : (int) $row->license_plan_rank;
        $detail->planType = $detail->planCode;
        $detail->expiresAt = $row->license_expires_at
            ? Carbon::parse($row->license_expires_at)->toISOString()
            : null;
        $detail->status = $row->license_status;
        $detail->currentMonthSlipCount = (int) $row->license_current_month_slip_count;
        $detail->currentStaffCount = (int) $row->license_current_staff_count;
        $detail->currentAccountCount = (int) $row->license_current_account_count;
        $detail->currentCurrencyTypeCount = (int) $row->license_current_currency_type_count;
        $detail->currentExchangePairCount = (int) $row->license_current_exchange_pair_count;
        $detail->maxSlipPerMonth = $row->license_max_slip_per_month === null
            ? null
            : (int) $row->license_max_slip_per_month;
        $detail->maxStaffCount = $row->license_max_staff_count === null
            ? null
            : (int) $row->license_max_staff_count;
        $detail->maxAccountCount = $row->license_max_account_count === null
            ? null
            : (int) $row->license_max_account_count;
        $detail->maxCurrencyTypeCount = $row->license_max_currency_type_count === null
            ? null
            : (int) $row->license_max_currency_type_count;
        $detail->maxExchangePairCount = $row->license_max_exchange_pair_count === null
            ? null
            : (int) $row->license_max_exchange_pair_count;

        return $detail;
    }

    protected function makeTenantBrandingDetail(stdClass $row): ?TenantBrandingDetail
    {
        if ($row->branding_id === null) {
            return null;
        }

        $detail = new TenantBrandingDetail();
        $detail->id = (int) $row->branding_id;
        $detail->tenantId = (int) $row->tenant_id;
        $detail->updateKey = (int) $row->branding_update_key;
        $detail->logoPath = $row->branding_logo_path;
        $detail->faviconPath = $row->branding_favicon_path;
        $detail->primaryColor = $row->branding_primary_color;
        $detail->secondaryColor = $row->branding_secondary_color;
        $detail->accentColor = $row->branding_accent_color;
        $detail->slipHeaderLayout = $row->branding_slip_header_layout
            ? json_decode($row->branding_slip_header_layout, true)
            : null;
        $detail->slipFooterLayout = $row->branding_slip_footer_layout
            ? json_decode($row->branding_slip_footer_layout, true)
            : null;

        return $detail;
    }

    protected function makeTenantSettingDetail(Collection $rows): ?TenantSettingDetail
    {
        $settingRows = $rows
            ->filter(fn (stdClass $row) => $row->setting_id !== null)
            ->unique(fn (stdClass $row) => (int) $row->setting_id)
            ->values();

        if ($settingRows->isEmpty()) {
            return null;
        }

        $detail = new TenantSettingDetail();
        $currencyRow = $settingRows->first(fn (stdClass $row) => $row->setting_key === 'currency_preferences');
        if ($currencyRow !== null) {
            $detail->default_currency_id = $currencyRow->setting_default_currency_id === null ? null : (int) $currencyRow->setting_default_currency_id;
            $detail->reporting_currency_id = $currencyRow->setting_reporting_currency_id === null ? null : (int) $currencyRow->setting_reporting_currency_id;
            $detail->effective_reporting_currency_id = $currencyRow->effective_reporting_currency_id === null
                ? $detail->reporting_currency_id
                : (int) $currencyRow->effective_reporting_currency_id;
            $detail->default_currency_symbol = $currencyRow->setting_default_currency_symbol;
            $detail->reporting_currency_symbol = $currencyRow->setting_reporting_currency_symbol;
            $detail->effective_reporting_currency_symbol = $currencyRow->effective_reporting_currency_symbol
                ?? $currencyRow->setting_reporting_currency_symbol;
            $detail->default_financial_unit = $currencyRow->setting_value;
            $detail->reporting_currency_recalculation = $currencyRow->currency_recalculation_id === null ? null : [
                'id' => (int) $currencyRow->currency_recalculation_id,
                'status' => $currencyRow->currency_recalculation_status,
                'window_start' => $currencyRow->currency_recalculation_window_start,
                'window_end' => $currencyRow->currency_recalculation_window_end,
                'missing_rates' => $currencyRow->currency_recalculation_missing_rates
                    ? json_decode($currencyRow->currency_recalculation_missing_rates, true)
                    : [],
            ];
        }
        $detail->items = $settingRows
            ->map(function (stdClass $row) {
                $item = new TenantSettingItem();
                $item->updateKey = (int) $row->setting_update_key;
                $item->key = $row->setting_key;
                $item->value = $row->setting_value;
                $item->category = $row->setting_category;

                return $item;
            })
            ->all();

        return $detail;
    }
}
