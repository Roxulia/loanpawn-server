<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantLenderUpsert;
use App\Http\Controllers\Controller;
use App\Rules\NrcRules;
use App\Services\TenantModule\TenantLenderService;
use App\Utility\NrcHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantLenderController extends Controller
{
    public function __construct(private TenantLenderService $lenderService) {}

    public function index(Request $request): JsonResponse
    {
        // Validation of lender directory filters
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'search' => ['nullable', 'string', 'max:120']]);
        return $this->successResponse($this->lenderService->list((int) ($validated['per_page'] ?? 15), $validated['search'] ?? null));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedIdentity($request);
        return $this->successResponse($this->lenderService->create($this->toData($request, $data))->toArray(), status: 201);
    }

    public function show(string $lenderCode): JsonResponse
    {
        return $this->successResponse($this->lenderService->detail($lenderCode)->toArray());
    }

    public function update(Request $request, string $lenderCode): JsonResponse
    {
        $data = $this->validatedIdentity($request, true);
        return $this->successResponse($this->lenderService->update($lenderCode, $this->toData($request, $data))->toArray());
    }

    public function destroy(string $lenderCode): JsonResponse
    {
        $this->lenderService->delete($lenderCode);
        return $this->successResponse();
    }

    private function validatedIdentity(Request $request, bool $updating = false): array
    {
        // Reuse of the established NRC validation contract
        $validator = Validator::make(array_merge($request->all(), ['_nrc' => true]), [
            'name' => ['required', 'string', 'max:120'], 'nrc_state' => ['nullable'], 'nrc_township' => ['nullable'],
            'nrc_citizen' => ['nullable'], 'nrc_number' => ['nullable', 'string'],
            '_nrc' => [new NrcRules($request->input('nrc_state'), $request->input('nrc_township'), $request->input('nrc_citizen'), $request->input('nrc_number'))],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'], 'note' => ['nullable', 'string'],
            'update_key' => [$updating ? 'required' : 'nullable', 'integer', 'min:0'],
        ]);
        return $validator->validate();
    }

    private function toData(Request $request, array $validated): TenantLenderUpsert
    {
        return new TenantLenderUpsert(
            name: $validated['name'], nrc: NrcHelper::buildNrcFromRequest($request), email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null, address: $validated['address'] ?? null, note: $validated['note'] ?? null,
            updateKey: (int) ($validated['update_key'] ?? 0),
        );
    }
}
