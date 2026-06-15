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
            ->leftJoin('tenant_contacts', 'tenant_contacts.tenant_id', '=', 'tenants.id')
            ->leftJoin('tenant_branding', 'tenant_branding.tenant_id', '=', 'tenants.id')
            ->leftJoin('tenant_settings', 'tenant_settings.tenant_id', '=', 'tenants.id')
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
                'tenant_licenses.plan_type as license_plan_type',
                'tenant_licenses.expires_at as license_expires_at',
                'tenant_licenses.status as license_status',
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
        $detail->planType = $row->license_plan_type;
        $detail->expireDate = $row->license_expires_at
            ? Carbon::parse($row->license_expires_at)->toISOString()
            : null;
        $detail->status = $row->license_status;

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
