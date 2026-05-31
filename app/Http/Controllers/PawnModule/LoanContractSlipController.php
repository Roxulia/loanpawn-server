<?php

namespace App\Http\Controllers\PawnModule;

use App\DataObjects\RequestObjects\LoanContractSlipCreate;
use App\DataObjects\RequestObjects\PawnCollateralItemCreate;
use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\Http\Controllers\Controller;
use App\Services\PawnModule\LoanContractServices\LookUpService;
use App\Services\PawnModule\LoanContractServices\ManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LoanContractSlipController extends Controller
{
    public function __construct(
        private LookUpService $lookUpService,
        private ManagementService $managementService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $validated = $validator->validated();

        return response()->json([
            'data' => $this->lookUpService->list((int) ($validated['per_page'] ?? 15))->toArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = array_merge($request->all(), [
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        $validator = Validator::make($input, [
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:120'],
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
            'collateral_items.*.image_url' => ['nullable', 'string', 'max:255'],
            'collateral_items.*.estimated_value' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.material_type_id' => ['nullable', 'integer'],
            'collateral_items.*.kyat' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.pal' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.yway' => ['nullable', 'numeric', 'min:0'],
            'collateral_items.*.item_status' => ['nullable', 'string', 'max:30'],
            'collateral_items.*.contains_gemstones' => ['nullable', 'boolean'],
            'collateral_items.*.gemstone_details' => ['nullable', 'array'],
            'collateral_items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'collateral_items.*.minimum_retail_price' => ['nullable', 'numeric', 'min:0'],
            'loan_amount' => ['required', 'numeric', 'min:0.01'],
            'interest_rate' => ['required', 'numeric', 'min:0.01'],
            'interest_type_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string'],
            'expiry_quota' => ['required', 'integer', 'min:1'],
            'expiry_quota_type' => ['required', 'string', 'in:Day,Week,Month,Year,day,week,month,year'],
            'created_by' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:120'],
        ]);

        if ($validator->fails()) {
            return $this->validationFailed($validator);
        }

        $validated = $validator->validated();
        $customer = $validated['customer'];
        $slip = $this->managementService->create(new LoanContractSlipCreate(
            customer: new TenantCustomerCreate(
                name: $customer['name'],
                email: $customer['email'] ?? null,
                phone: $customer['phone'] ?? null,
                address: $customer['address'] ?? null,
                trustScore: (int) ($customer['trust_score'] ?? 0),
                note: $customer['note'] ?? null,
            ),
            collateralItems: array_map(
                fn (array $item): PawnCollateralItemCreate => $this->makeCollateralItemCreate($item),
                $validated['collateral_items'],
            ),
            loanAmount: (float) $validated['loan_amount'],
            interestRate: (float) $validated['interest_rate'],
            interestTypeId: (int) $validated['interest_type_id'],
            notes: $validated['notes'] ?? null,
            expiryQuota: (int) $validated['expiry_quota'],
            expiryQuotaType: $validated['expiry_quota_type'],
            createdBy: $validated['created_by'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
        ));

        return response()->json([
            'message' => 'Loan contract slip created successfully.',
            'data' => $slip->toArray(),
        ], 201);
    }

    public function show(string $slipNo): JsonResponse
    {
        return response()->json([
            'data' => $this->lookUpService->findBySlipNo($slipNo)->toArray(),
        ]);
    }

    public function destroy(string $slipNo): JsonResponse
    {
        $this->managementService->deleteBySlipNo($slipNo);

        return response()->json([
            'message' => 'Loan contract slip deleted successfully.',
        ]);
    }

    protected function makeCollateralItemCreate(array $item): PawnCollateralItemCreate
    {
        return new PawnCollateralItemCreate(
            type: $item['type'],
            name: $item['name'],
            description: $item['description'] ?? null,
            brandName: $item['brand_name'] ?? null,
            imageUrl: $item['image_url'] ?? null,
            estimatedValue: (float) ($item['estimated_value'] ?? 0),
            materialTypeId: $item['material_type_id'] ?? null,
            kyat: (float) ($item['kyat'] ?? 0),
            pal: (float) ($item['pal'] ?? 0),
            yway: (float) ($item['yway'] ?? 0),
            itemStatus: $item['item_status'] ?? 'active',
            containsGemstones: (bool) ($item['contains_gemstones'] ?? false),
            gemstoneDetails: $item['gemstone_details'] ?? null,
            quantity: (int) ($item['quantity'] ?? 1),
            minimumRetailPrice: (float) ($item['minimum_retail_price'] ?? 0),
        );
    }

    protected function validationFailed($validator): JsonResponse
    {
        return response()->json([
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422);
    }
}
