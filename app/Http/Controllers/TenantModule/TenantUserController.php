<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantUserCreate;
use App\DataObjects\RequestObjects\TenantUserUpdate;
use App\DataObjects\RequestObjects\FinancialAccountAssignmentUpdate;
use App\Http\Controllers\Controller;
use App\Rules\NrcRules;
use App\Rules\PasswordRules;
use App\Services\TenantModule\AuthService;
use App\Services\TenantModule\TenantUserService;
use App\Services\TenantModule\Accounting\FinancialAccountAssignmentService;
use App\Utility\MessageCode;
use App\Utility\NrcHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantUserController extends Controller
{
    public function __construct(
        private TenantUserService $tenantUserService,
        private AuthService $authService,
        private FinancialAccountAssignmentService $financialAccountAssignmentService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $users = $this->tenantUserService->list((int) ($validated['per_page'] ?? 15));

        return $this->successResponse($users->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make(array_merge($request->all(), ['_nrc' => true]), [
            'name' => ['required', 'string', 'max:120'],
            'nrc_state' => ['required'],
            'nrc_township' => ['required'],
            'nrc_citizen' => ['required'],
            'nrc_number' => ['required','min:6','max:6'],

            '_nrc' => [
                new NrcRules(
                    $request->input('nrc_state'),
                    $request->input('nrc_township'),
                    $request->input('nrc_citizen'),
                    $request->input('nrc_number'),
                ),
            ],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        $validated = $validator->validated();
        $nrc = NrcHelper::buildNrcFromRequest($request);
        $user = $this->tenantUserService->createForCurrentTenant(new TenantUserCreate(
            name: $validated['name'],
            nrc: $nrc,
            phone: $validated['phone'],
            password: null,
            email: $validated['email'],
            address: $validated['address'] ?? null,
            tenantId: null,
            roleId: $validated['role_id'] ?? null,
            status: 'inactive',
        ));

        return $this->successResponse($user->toArray(), $this->responseMessage(MessageCode::TenantUserCreated), 201);
    }

    public function update(Request $request, string $tenantUserCode): JsonResponse
    {
        $validator = Validator::make(array_merge($request->all(), ['_nrc' => true]), [
            'name' => ['nullable', 'string', 'max:120'],
            'nrc_state' => ['nullable'],
            'nrc_township' => ['nullable'],
            'nrc_citizen' => ['nullable'],
            'nrc_number' => ['nullable', 'min:6','max:6'],

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
            'address' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }


        $validated = $validator->validated();
        $nrc = NrcHelper::buildNrcFromRequest($request);
        $user = $this->tenantUserService->update(new TenantUserUpdate(
            userId: $this->tenantUserService->resolveIdByCode($tenantUserCode),
            code: $tenantUserCode,
            updateKey: $validated['update_key'] ?? 0,
            name: $validated['name'] ?? null,
            nrc: $nrc,
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            address: $validated['address'] ?? null,
            roleId: $validated['role_id'] ?? null,
        ));

        return $this->successResponse($user->toArray(), $this->responseMessage(MessageCode::TenantUserUpdated));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', PasswordRules::strong(), 'max:255', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $this->tenantUserService->changeCurrentUserPassword(
            currentPassword: $validated['current_password'],
            newPassword: $validated['password'],
        );

        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantUserPasswordChanged));
    }

    public function resetPasswordToDefault(Request $request, string $tenantUserCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'logoutFromAll' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $this->tenantUserService->resetPasswordToDefault(
            tenantUserId: $this->tenantUserService->resolveIdByCode($tenantUserCode),
            logoutFromAll: (bool) ($validated['logoutFromAll'] ?? false),
        );

        $message = $this->responseMessage(MessageCode::TenantUserPasswordReset);

        return $this->successResponse(['message' => $message], $message);
    }

    public function show(string $tenantUserCode): JsonResponse
    {
        $user = $this->tenantUserService->showByCode($tenantUserCode);

        return $this->successResponse($user->toArray());
    }

    public function updatePermissions(Request $request, string $tenantUserCode): JsonResponse
    {
        $rules = [];

        foreach (array_keys(config('tenant_permissions.codes', [])) as $permission) {
            $rules[$permission] = ['nullable', 'boolean'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $user = $this->tenantUserService->updatePermissions($this->tenantUserService->resolveIdByCode($tenantUserCode), $validator->validated());

        return $this->successResponse($user->toArray(), $this->responseMessage(MessageCode::TenantUserPermissionsUpdated));
    }

    public function updateFinancialAccountAssignments(Request $request, string $tenantUserCode): JsonResponse
    {
        $validated = $request->validate([
            'financial_account_ids' => ['required', 'array'],
            'financial_account_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);

        $accounts = $this->financialAccountAssignmentService->updateForUser(
            $tenantUserCode,
            new FinancialAccountAssignmentUpdate($validated['financial_account_ids']),
        );

        return $this->successResponse(
            ['financial_accounts' => array_map(fn ($account) => $account->toArray(), $accounts)],
            $this->responseMessage(MessageCode::FinanceAssignmentsUpdated),
        );
    }

    public function destroy(string $tenantUserCode): JsonResponse
    {
        $currentUser = $this->authService->getCurrentUser();
        if($currentUser->code === $tenantUserCode)
        {
            return $this->errorResponse(message : $this->responseMessage(MessageCode::SelfDelete),statusCode : 400);
        }
        $this->tenantUserService->delete($this->tenantUserService->resolveIdByCode($tenantUserCode));

        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantUserDeleted));
    }
}
