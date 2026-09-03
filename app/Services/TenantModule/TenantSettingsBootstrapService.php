<?php

namespace App\Services\TenantModule;

use App\DataObjects\ResponseObjects\CurrencyResource;
use App\DataObjects\ResponseObjects\TenantSettingsBootstrapResource;
use App\Services\PlatformModule\TenantServices\TenantBrandingService;
use App\Services\PlatformModule\TenantServices\TenantContactService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;

class TenantSettingsBootstrapService
{
    public function __construct(
        private TenantUserPermissionService $permissionService,
        private TenantLicenseService $licenseService,
        private TenantBrandingService $brandingService,
        private TenantContactService $contactService,
        private TenantSettingService $settingService,
        private TenantCurrencyService $currencyService,
        private TenantAccountingDayService $accountingDayService,
        private FinancialAccountTypeService $financialAccountTypeService,
        private DefaultDataService $defaultDataService,
    ) {}

    public function tenant(): TenantSettingsBootstrapResource
    {
        $permissions = ['manage_slip_document', 'manage_tenant_contact', 'manage_tenant_timezone'];
        $this->permissionService->authorizeAnyPermission($permissions);
        $sections = [];

        if ($this->hasPermission('manage_slip_document')) {
            if ($this->licenseService->currentTenantHasFeature('tenant_branding')) {
                $sections['branding'] = $this->brandingService->getCurrentTenantBranding()->toArray();
            }
            $sections['tenant_setting'] = [
                'default_tenant_user_password' => $this->settingService->getCurrentTenantDefaultUserPassword(),
            ];
        }

        if ($this->hasPermission('manage_tenant_contact')) {
            $sections['contact'] = $this->contactService->getCurrentTenantContact()->toArray();
        }

        if ($this->hasPermission('manage_tenant_timezone')
            && $this->licenseService->currentTenantHasFeature('tenant_timezone_management')) {
            $sections['timezone'] = $this->settingService->getCurrentTenantTimezone()->toArray();
            $sections['timezone_options'] = array_values(timezone_identifiers_list());
        }

        return new TenantSettingsBootstrapResource($sections);
    }

    public function finance(): TenantSettingsBootstrapResource
    {
        $permissions = [
            'list_currency',
            'update_default_currency',
            'update_reporting_currency',
            'update_default_financial_unit',
            'manage_accounting_day_schedule',
            'list_financial_account_type',
            'manage_interest_process_settings',
        ];
        $this->permissionService->authorizeAnyPermission($permissions);
        $sections = [];

        if ($this->hasPermission('list_currency')
            && $this->licenseService->currentTenantHasFeature('currency_management')) {
            $sections['currency_preferences'] = $this->settingService->getCurrentTenantCurrencyPreferences()->toArray();
            $sections['currency_options'] = collect($this->currencyService->list(100)->items())
                ->filter(fn ($currency) => $currency->is_active)
                ->map(fn ($currency) => CurrencyResource::fromModel($currency)->toArray())
                ->values()
                ->all();
        }

        if ($this->hasPermission('manage_accounting_day_schedule')
            && $this->licenseService->currentTenantHasFeature('automatic_open_close')) {
            $sections['accounting_schedule'] = $this->accountingDayService->schedule()->toArray();
        }

        if ($this->hasPermission('list_financial_account_type')
            && $this->licenseService->currentTenantHasFeature('accounting_management')) {
            $sections['financial_account_types'] = $this->financialAccountTypeService->list(5)->toArray();
        }

        if ($this->hasPermission('manage_interest_process_settings')
            && $this->licenseService->currentTenantHasFeature('advanced_interest_process')) {
            $sections['interest_process_settings'] = $this->settingService->getCurrentTenantInterestProcessSettings()->toArray();
        }

        return new TenantSettingsBootstrapResource($sections);
    }

    public function defaultData(): TenantSettingsBootstrapResource
    {
        $permissions = ['list_interest_type', 'list_expense_type', 'list_material_type', 'list_item_category_type'];
        $this->permissionService->authorizeAnyPermission($permissions);
        $sections = [];

        if ($this->hasPermission('list_interest_type')) {
            $sections['interest_types'] = $this->defaultDataService->listInterestTypes(5)->toArray();
        }
        if ($this->hasPermission('list_expense_type')) {
            $sections['expense_types'] = $this->defaultDataService->listExpenseTypes(5)->toArray();
        }
        if ($this->hasPermission('list_material_type')) {
            $sections['material_types'] = $this->defaultDataService->listMaterialTypes(5)->toArray();
        }
        if ($this->hasPermission('list_item_category_type')) {
            $sections['item_category_types'] = $this->defaultDataService->listItemCategoryTypes(5)->toArray();
        }

        return new TenantSettingsBootstrapResource($sections);
    }

    private function hasPermission(string $permission): bool
    {
        return $this->permissionService->currentUserHasAnyPermission([$permission]);
    }
}
