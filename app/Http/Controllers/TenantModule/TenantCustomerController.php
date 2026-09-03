<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantCustomerCreate;
use App\DataObjects\RequestObjects\TenantCustomerUpdate;
use App\Http\Controllers\Controller;
use App\Models\CoreModule\TenantCustomer;
use App\Rules\NrcRules;
use App\Services\TenantModule\TenantCustomerService;
use App\Utility\MessageCode;
use App\Utility\NrcHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantCustomerController extends Controller
{
    public function __construct(
        private TenantCustomerService $tenantCustomerService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'show_unknown_customer' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $customers = $this->tenantCustomerService->list(
            (int) ($validated['per_page'] ?? 15),
            $validated['search'] ?? null,
            $request->boolean('show_unknown_customer'),
        );

        return $this->successResponse($customers->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make(array_merge($request->all(), ['_nrc' => true]), [
            'name' => ['required', 'string', 'max:120'],
            'nrc_state' => ['nullable'],
            'nrc_township' => ['nullable'],
            'nrc_citizen' => ['nullable'],
            'nrc_number' => ['nullable', 'string'],

            '_nrc' => [
                new NrcRules(
                    $request->input('nrc_state'),
                    $request->input('nrc_township'),
                    $request->input('nrc_citizen'),
                    $request->input('nrc_number'),
                ),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'trust_score' => ['nullable', 'integer', 'min:0', 'max:255'],
            'note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        $nrc = NrcHelper::buildNrcFromRequest($request);

        $result = $this->tenantCustomerService->createForCurrentTenant(new TenantCustomerCreate(
            name: $validated['name'],
            nrc: $nrc,
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            address: $validated['address'] ?? null,
            trustScore: (int) ($validated['trust_score'] ?? TenantCustomer::DEFAULT_TRUST_SCORE),
            note: $validated['note'] ?? null,
        ));

        return $this->successResponse(
            $result->toArray(),
            $result->created
                ? $this->responseMessage(MessageCode::TenantCustomerCreated)
                : $this->responseMessage(MessageCode::ApiResponseSuccess),
            $result->created ? 201 : 200,
        );
    }

    public function show(string $tenantCustomerCode): JsonResponse
    {
        $customer = $this->tenantCustomerService->showByCode($tenantCustomerCode);

        return $this->successResponse($customer->toArray());
    }

    public function update(Request $request, string $tenantCustomerCode): JsonResponse
    {
        $validator = Validator::make(array_merge($request->all(), ['_nrc' => true]), [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'nrc_state' => ['nullable'],
            'nrc_township' => ['nullable'],
            'nrc_citizen' => ['nullable'],
            'nrc_number' => ['nullable', 'string'],

            '_nrc' => [
                new NrcRules(
                    $request->input('nrc_state'),
                    $request->input('nrc_township'),
                    $request->input('nrc_citizen'),
                    $request->input('nrc_number'),
                ),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'trust_score' => ['nullable', 'integer', 'min:0', 'max:255'],
            'note' => ['nullable', 'string'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        $nrc = NrcHelper::buildNrcFromRequest($request);
        $providedFields = array_values(array_filter([
            $request->exists('name') ? 'name' : null,
            collect(['nrc_state', 'nrc_township', 'nrc_citizen', 'nrc_number'])
                ->contains(fn (string $field): bool => $request->exists($field)) ? 'nrc' : null,
            $request->exists('email') ? 'email' : null,
            $request->exists('phone') ? 'phone' : null,
            $request->exists('address') ? 'address' : null,
            $request->exists('trust_score') ? 'trustScore' : null,
            $request->exists('note') ? 'note' : null,
        ]));

        $customer = $this->tenantCustomerService->update(new TenantCustomerUpdate(
            customerId: $this->tenantCustomerService->resolveIdByCode($tenantCustomerCode),
            code: $tenantCustomerCode,
            updateKey: $validated['update_key']??0,
            name: $validated['name'] ?? null,
            nrc: $nrc,
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            address: $validated['address'] ?? null,
            trustScore: array_key_exists('trust_score', $validated) ? (int) $validated['trust_score'] : null,
            note: $validated['note'] ?? null,
            providedFields: $providedFields,
        ));

        return $this->successResponse($customer->toArray(), $this->responseMessage(MessageCode::TenantCustomerUpdated));
    }

    public function destroy(string $tenantCustomerCode): JsonResponse
    {
        $this->tenantCustomerService->delete($this->tenantCustomerService->resolveIdByCode($tenantCustomerCode));

        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantCustomerDeleted));
    }
}
