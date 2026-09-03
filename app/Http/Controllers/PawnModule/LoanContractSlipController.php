<?php

namespace App\Http\Controllers\PawnModule;

use App\DataObjects\RequestObjects\LoanContractSlipCreate;
use App\DataObjects\RequestObjects\PartialPrincipalCollectionCreate;
use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\DataObjects\RequestObjects\SlipCompoundScheduleUpdate;
use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\Http\Controllers\Controller;
use App\Models\CoreModule\TenantCustomer;
use App\Rules\NrcRules;
use App\Services\PawnModule\LoanContractServices\LookUpService;
use App\Services\PawnModule\LoanContractServices\ManagementService;
use App\Services\PawnModule\PawnInterestProcessService;
use App\Services\PlatformModule\TenantServices\TenantSettingService;
use App\Services\TenantModule\FinancialUnitService;
use App\Services\ExchangeRate\ReportingExchangeRateService;
use App\Utility\MessageCode;
use App\Utility\NrcHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class LoanContractSlipController extends Controller
{
    public function __construct(
        private LookUpService $lookUpService,
        private ManagementService $managementService,
        private FinancialUnitService $financialUnitService,
        private ReportingExchangeRateService $exchangeRateService,
        private PawnInterestProcessService $interestProcessService,
        private TenantSettingService $tenantSettingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        return $this->successResponse($this->lookUpService->list((int) ($validated['per_page'] ?? 15))->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $input = array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
            '_nrc' => true,
        ]);

        $customerInfoRequired = $this->tenantSettingService->currentTenantRequiresLoanSlipCustomerInfo();
        $validator = Validator::make($input, [
            'customer' => ['required', 'array'],
            'customer.name' => [$customerInfoRequired ? 'required' : 'nullable', 'string', 'max:120'],
            'customer.nrc_state' => ['nullable'],
            'customer.nrc_township' => ['nullable'],
            'customer.nrc_citizen' => ['nullable'],
            'customer.nrc_number' => ['nullable', 'min:6', 'max:6'],
            'customer._nrc' => [
                new NrcRules(
                    data_get($input, 'customer.nrc_state'),
                    data_get($input, 'customer.nrc_township'),
                    data_get($input, 'customer.nrc_citizen'),
                    data_get($input, 'customer.nrc_number'),
                ),
            ],
            'customer.email' => ['nullable', 'email', 'max:120'],
            'customer.phone' => ['nullable', 'string', 'max:40'],
            'customer.address' => ['nullable', 'string'],
            'customer.trust_score' => ['nullable', 'integer', 'min:0'],
            'customer.note' => ['nullable', 'string'],
            'collateral_items' => ['required', 'array', 'min:1'],
            'collateral_items.*.type' => ['required', 'string', 'in:Jewellery,Normal,jewellery,normal'],
            'collateral_items.*.name' => ['required', 'string', 'max:120'],
            'collateral_items.*.description' => ['nullable', 'string'],
            'collateral_items.*.brand_name' => ['nullable', 'string', 'max:80'],
            'collateral_items.*.image_reference' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'collateral_items.*.estimated_value' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.estimated_value_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class)],
            'collateral_items.*.material_type_id' => ['nullable', 'integer'],
            'collateral_items.*.material_price_per_kyat' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.material_price_per_kyat_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class)],
            'collateral_items.*.item_category_type_id' => ['nullable', 'integer'],
            'collateral_items.*.kyat' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.pal' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.yway' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.item_status' => ['nullable', 'string', 'max:30'],
            'collateral_items.*.contains_gemstones' => ['nullable', 'boolean'],
            'collateral_items.*.gemstone_details' => ['nullable', 'array'],
            'collateral_items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'collateral_items.*.minimum_retail_price' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.minimum_retail_price_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class)],
            'loan_amount' => ['required', 'numeric', 'min:0.01'],
            'loan_amount_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class), 'exclude_without:loan_amount'],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
            'interest_rate' => ['required', 'numeric', 'min:0.01'],
            'interest_type_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'expiry_quota' => ['required', 'integer', 'min:1'],
            'expiry_quota_type' => ['required', 'string', 'in:Day,Week,Month,Year,day,week,month,year'],
            'created_by' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);
        $validator->after(function ($validator) use ($customerInfoRequired, $input): void {
            if (! $customerInfoRequired) {
                return;
            }

            $nrc = NrcHelper::buildCustomerNrc((array) data_get($input, 'customer', []));
            $email = trim((string) data_get($input, 'customer.email', ''));
            $phone = trim((string) data_get($input, 'customer.phone', ''));

            if ($nrc === null && $email === '' && $phone === '') {
                $validator->errors()->add('customer', 'One of NRC, email, or phone is required.');
            }
        });

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $customer = $validated['customer'];
        $nrc = NrcHelper::buildCustomerNrc($customer);
        $slip = $this->managementService->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: $customer['name'],
                nrc: $nrc,
                email: $customer['email'] ?? null,
                phone: $customer['phone'] ?? null,
                address: $customer['address'] ?? null,
                trustScore: (int) ($customer['trust_score'] ?? TenantCustomer::DEFAULT_TRUST_SCORE),
                note: $customer['note'] ?? null,
            ),
            collateralItems: array_map(
                fn (array $item): PawnCollateralItemCreate => $this->makeCollateralItemCreate($item),
                $validated['collateral_items'],
            ),
            loanAmount: $this->financialUnitService->toBase($validated['loan_amount'], $validated['loan_amount_unit'] ?? null, 999_999_999_999.99),
            interestRate: (float) $validated['interest_rate'],
            accountId: isset($validated['account_id']) ? (int) $validated['account_id'] : null,
            reportingExchangeRate: $this->exchangeRateService->manualMultiplier(
                isset($validated['reporting_exchange_rate']) ? (float) $validated['reporting_exchange_rate'] : null,
                (bool) ($validated['reporting_exchange_rate_inversed'] ?? false),
            ),
            interestTypeId: (int) $validated['interest_type_id'],
            notes: $validated['notes'] ?? null,
            expiryQuota: (int) $validated['expiry_quota'],
            expiryQuotaType: $validated['expiry_quota_type'],
            createdBy: $validated['created_by'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return $this->successResponse($slip->toArray(), $this->responseMessage(MessageCode::PawnLoanContractSlipCreated), 201);
    }

    public function show(string $slipNo): JsonResponse
    {
        return $this->successResponse($this->lookUpService->findBySlipNo($slipNo)->toArray());
    }

    public function destroy(string $slipNo): JsonResponse
    {
        $this->managementService->deleteBySlipNo($slipNo);

        return $this->successResponse(message: $this->responseMessage(MessageCode::PawnLoanContractSlipDeleted));
    }

    public function updateCompoundSchedule(Request $request, string $slipNo): JsonResponse
    {
        $validated = $request->validate([
            'slip_update_key' => ['required', 'integer', 'min:0'],
            'enabled' => ['required', 'boolean'],
            'compound_every' => ['nullable', 'integer', 'min:1'],
            'compound_every_type' => ['nullable', 'string', 'in:Day,Week,Month,day,week,month'],
            'next_compound_at' => ['nullable', 'date'],
        ]);

        return $this->successResponse($this->interestProcessService->updateSchedule(
            $slipNo,
            new SlipCompoundScheduleUpdate(
                slipUpdateKey: (int) $validated['slip_update_key'],
                enabled: (bool) $validated['enabled'],
                compoundEvery: isset($validated['compound_every']) ? (int) $validated['compound_every'] : null,
                compoundEveryType: $validated['compound_every_type'] ?? null,
                nextCompoundAt: $validated['next_compound_at'] ?? null,
            ),
        ));
    }

    public function compoundInterest(string $slipNo): JsonResponse
    {
        return $this->successResponse($this->interestProcessService->compoundBySlipNo($slipNo));
    }

    public function collectPartialPrincipal(Request $request, string $slipNo): JsonResponse
    {
        $validated = $request->validate([
            'slip_update_key' => ['required', 'integer', 'min:0'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'amount_unit' => ['nullable', 'string', Rule::enum(\App\Enums\FinancialUnit::class), 'exclude_without:amount'],
            'accept_account_id' => ['nullable', 'integer', 'min:1'],
            'reporting_exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'reporting_exchange_rate_inversed' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse($this->interestProcessService->collectPartialPrincipal(
            $slipNo,
            new PartialPrincipalCollectionCreate(
                slipUpdateKey: (int) $validated['slip_update_key'],
                amount: $this->financialUnitService->toBase($validated['amount'], $validated['amount_unit'] ?? null, 999_999_999_999.99),
                acceptAccountId: isset($validated['accept_account_id']) ? (int) $validated['accept_account_id'] : null,
                reportingExchangeRate: $this->exchangeRateService->manualMultiplier(
                    isset($validated['reporting_exchange_rate']) ? (float) $validated['reporting_exchange_rate'] : null,
                    (bool) ($validated['reporting_exchange_rate_inversed'] ?? false),
                ),
            ),
        ));
    }

    protected function makeCollateralItemCreate(array $item): PawnCollateralItemCreate
    {
        return new PawnCollateralItemCreate(
            type: $item['type'],
            name: $item['name'],
            description: $item['description'] ?? null,
            brandName: $item['brand_name'] ?? null,
            imageReference: $item['image_reference'] ?? null,
            estimatedValue: $this->financialUnitService->toBase($item['estimated_value'] ?? 0, $item['estimated_value_unit'] ?? null, 999_999_999_999.99),
            materialTypeId: $item['material_type_id'] ?? null,
            materialPricePerKyat: $this->financialUnitService->toBase($item['material_price_per_kyat'] ?? 0, $item['material_price_per_kyat_unit'] ?? null, 999_999_999_999.99),
            itemCategoryTypeId: $item['item_category_type_id'] ?? null,
            kyat: (float) ($item['kyat'] ?? 0),
            pal: (float) ($item['pal'] ?? 0),
            yway: (float) ($item['yway'] ?? 0),
            itemStatus: $item['item_status'] ?? 'active',
            containsGemstones: (bool) ($item['contains_gemstones'] ?? false),
            gemstoneDetails: $item['gemstone_details'] ?? null,
            quantity: (int) ($item['quantity'] ?? 1),
            minimumRetailPrice: $this->financialUnitService->toBase($item['minimum_retail_price'] ?? 0, $item['minimum_retail_price_unit'] ?? null, 999_999_999_999.99),
        );
    }
}
