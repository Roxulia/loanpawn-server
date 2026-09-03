<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\DefaultDataCreate;
use App\DataObjects\RequestObjects\InterestProcessSettingsUpdate;
use App\DataObjects\RequestObjects\TenantBrandingUpdate;
use App\DataObjects\RequestObjects\TenantContactUpdate;
use App\DataObjects\RequestObjects\TenantCurrencySettingsUpdate;
use App\DataObjects\RequestObjects\TenantDefaultUserPasswordUpdate;
use App\DataObjects\RequestObjects\LoanSlipCreationSettingsUpdate;
use App\DataObjects\RequestObjects\TenantSettingsUpdate;
use App\DataObjects\RequestObjects\TenantTimezoneUpdate;
use App\Http\Controllers\Controller;
use App\Rules\PasswordRules;
use App\Services\PlatformModule\TenantServices\TenantBrandingService;
use App\Services\PlatformModule\TenantServices\TenantContactService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TenantModule\DefaultDataService;
use App\Services\TenantModule\TenantSettingsService;
use App\Services\TenantModule\TenantSettingsBootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantSettingsController extends Controller
{
    public function __construct(
        private TenantSettingService $tenantSettingService,
        private TenantBrandingService $tenantBrandingService,
        private TenantContactService $tenantContactService,
        private DefaultDataService $defaultDataService,
        private TenantSettingsService $tenantSettingsService,
        private TenantSettingsBootstrapService $tenantSettingsBootstrapService,
    ) {}

    public function tenantBootstrap(): JsonResponse
    {
        return $this->successResponse($this->tenantSettingsBootstrapService->tenant()->toArray());
    }

    public function financeBootstrap(): JsonResponse
    {
        return $this->successResponse($this->tenantSettingsBootstrapService->finance()->toArray());
    }

    public function defaultDataBootstrap(): JsonResponse
    {
        return $this->successResponse($this->tenantSettingsBootstrapService->defaultData()->toArray());
    }

    public function show(): JsonResponse
    {
        return $this->successResponse([
            'branding' => $this->tenantBrandingService->getCurrentTenantBranding()->toArray(),
            'contact' => $this->tenantContactService->getCurrentTenantContact()->toArray(),
            'tenant_setting' => [
                'default_tenant_user_password' => $this->tenantSettingService->getCurrentTenantDefaultUserPassword(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'branding' => ['nullable', 'array'],
            'branding.primary_color' => ['nullable', 'string', 'max:30'],
            'branding.secondary_color' => ['nullable', 'string', 'max:30'],
            'branding.accent_color' => ['nullable', 'string', 'max:30'],
            'contact' => ['nullable', 'array'],
            'contact.address' => ['nullable', 'string'],
            'contact.phone' => ['nullable', 'string', 'max:40'],
            'contact.city' => ['nullable', 'string', 'max:120'],
            'contact.country' => ['nullable', 'string', 'max:120'],
            'tenant_setting' => ['nullable', 'array'],
            'tenant_setting.default_tenant_user_password' => ['nullable', 'string', PasswordRules::strong(), 'max:255'],
            'tenant_setting.update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        $this->tenantSettingsService->updateCurrentTenantSettings(new TenantSettingsUpdate(
            branding: array_key_exists('branding', $validated)
                ? $this->makeTenantBrandingUpdate($validated['branding'] ?? [])
                : null,
            contact: array_key_exists('contact', $validated)
                ? $this->makeTenantContactUpdate($validated['contact'] ?? [])
                : null,
            defaultUserPassword: isset($validated['tenant_setting']['default_tenant_user_password'])
                ? $this->makeTenantDefaultUserPasswordUpdate($validated['tenant_setting'])
                : null,
        ));

        return $this->show();
    }

    public function updateBranding(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'primary_color' => ['nullable', 'string', 'max:30'],
            'secondary_color' => ['nullable', 'string', 'max:30'],
            'accent_color' => ['nullable', 'string', 'max:30'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $branding = $this->tenantBrandingService->updateCurrentTenantBranding(
            $this->makeTenantBrandingUpdate($validated),
        );

        return $this->successResponse($branding->toArray());
    }

    public function updateContact(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $contact = $this->tenantContactService->updateCurrentTenantContact(
            $this->makeTenantContactUpdate($validated),
        );

        return $this->successResponse($contact->toArray());
    }

    public function contact(): JsonResponse
    {
        return $this->successResponse($this->tenantContactService->getCurrentTenantContact()->toArray());
    }

    public function updateTenantDefaultUserPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'default_tenant_user_password' => ['required', 'string', PasswordRules::strong(), 'max:255'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $setting = $this->tenantSettingService->updateCurrentTenantDefaultUserPassword(
            $this->makeTenantDefaultUserPasswordUpdate($validated),
        );

        return $this->successResponse($setting->toArray());
    }

    public function loanSlipCreationSettings(): JsonResponse
    {
        return $this->successResponse($this->tenantSettingService->getCurrentTenantLoanSlipCreationSettings()->toArray());
    }

    public function updateLoanSlipCreationSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_info_required' => ['required', 'boolean'],
            'update_key' => ['required', 'integer', 'min:0'],
        ]);

        return $this->successResponse($this->tenantSettingService->updateCurrentTenantLoanSlipCreationSettings(
            new LoanSlipCreationSettingsUpdate(
                customerInfoRequired: (bool) $validated['customer_info_required'],
                updateKey: (int) $validated['update_key'],
            )
        )->toArray());
    }

    public function currencyPreferences(): JsonResponse
    {
        return $this->successResponse($this->tenantSettingService->getCurrentTenantCurrencyPreferences()->toArray());
    }

    public function updateCurrencyPreferences(Request $request): JsonResponse
    {
        $data = TenantCurrencySettingsUpdate::fromValidated(
            Validator::make($request->all(), TenantCurrencySettingsUpdate::rules())->validate()
        );

        return $this->successResponse(
            $this->tenantSettingService->updateCurrentTenantCurrencyPreferences($data)->toArray(),
            $this->responseMessage(\App\Utility\MessageCode::FinanceTenantCurrencyUpdated),
        );
    }

    public function interestProcessSettings(): JsonResponse
    {
        return $this->successResponse($this->tenantSettingService->getCurrentTenantInterestProcessSettings()->toArray());
    }

    public function updateInterestProcessSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'compounding_enabled' => ['required', 'boolean'],
            'partial_principal_collection_enabled' => ['required', 'boolean'],
            'update_key' => ['required', 'integer', 'min:0'],
        ]);

        return $this->successResponse($this->tenantSettingService->updateCurrentTenantInterestProcessSettings(
            new InterestProcessSettingsUpdate(
                compoundingEnabled: (bool) $validated['compounding_enabled'],
                partialPrincipalCollectionEnabled: (bool) $validated['partial_principal_collection_enabled'],
                updateKey: (int) $validated['update_key'],
            )
        )->toArray());
    }

    public function timezone(): JsonResponse
    {
        return $this->successResponse($this->tenantSettingService->getCurrentTenantTimezone()->toArray());
    }

    public function timezoneOptions(): JsonResponse
    {
        return $this->successResponse(array_values(timezone_identifiers_list()));
    }

    public function updateTimezone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'string', 'timezone'],
            'update_key' => ['required', 'integer', 'min:0'],
        ]);
        $setting = $this->tenantSettingService->updateCurrentTenantTimezone(new TenantTimezoneUpdate(
            timezone: $validated['timezone'],
            updateKey: (int) $validated['update_key'],
        ));

        return $this->successResponse($setting->toArray());
    }

    public function createInterestType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80'],
            'duration_in_days' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $data = $this->defaultDataService->createCurrentTenantInterestType(
            $this->makeDefaultDataCreate($validator->validated()),
        );

        return $this->successResponse($data, statusCode: 201);
    }

    public function createExpenseType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $data = $this->defaultDataService->createCurrentTenantExpenseType(
            $this->makeDefaultDataCreate($validator->validated()),
        );

        return $this->successResponse($data, statusCode: 201);
    }

    public function createMaterialType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $data = $this->defaultDataService->createCurrentTenantMaterialType(
            $this->makeDefaultDataCreate($validator->validated()),
        );

        return $this->successResponse($data, statusCode: 201);
    }

    public function createItemCategoryType(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $data = $this->defaultDataService->createCurrentTenantItemCategoryType(
            $this->makeDefaultDataCreate($validator->validated()),
        );

        return $this->successResponse($data, statusCode: 201);
    }

    protected function makeDefaultDataCreate(array $data): DefaultDataCreate
    {
        return new DefaultDataCreate(
            name: $data['name'],
            code: $data['code'] ?? null,
            durationInDays: $data['duration_in_days'] ?? null,
        );
    }

    protected function makeTenantBrandingUpdate(array $data): TenantBrandingUpdate
    {
        return new TenantBrandingUpdate(
            updateKey: (int) ($data['update_key'] ?? 0),
            primaryColor: $data['primary_color'] ?? null,
            secondaryColor: $data['secondary_color'] ?? null,
            accentColor: $data['accent_color'] ?? null,
        );
    }

    protected function makeTenantContactUpdate(array $data): TenantContactUpdate
    {
        return new TenantContactUpdate(
            updateKey: (int) ($data['update_key'] ?? 0),
            address: $data['address'] ?? null,
            phone: $data['phone'] ?? null,
            city: $data['city'] ?? null,
            country: $data['country'] ?? null,
        );
    }

    protected function makeTenantDefaultUserPasswordUpdate(array $data): TenantDefaultUserPasswordUpdate
    {
        return new TenantDefaultUserPasswordUpdate(
            defaultTenantUserPassword: $data['default_tenant_user_password'],
            updateKey: (int) ($data['update_key'] ?? 0),
        );
    }
}
