<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantUserCreate;
use App\DataObjects\RequestObjects\TenantUserUpdate;
use App\Http\Controllers\Controller;
use App\Rules\PasswordRules;
use App\Services\TenantModule\TenantUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantUserController extends Controller
{
    public function __construct(
        private TenantUserService $tenantUserService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $users = $this->tenantUserService->list((int) ($validated['per_page'] ?? 15));

        return response()->json([
            'data' => $users->toArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'nrc' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $user = $this->tenantUserService->createForCurrentTenant(new TenantUserCreate(
            name: $validated['name'],
            nrc: $validated['nrc'],
            phone: $validated['phone'],
            password: null,
            email: $validated['email'],
            address: $validated['address'] ?? null,
            tenantId: null,
            roleId: $validated['role_id'] ?? null,
            status: $validated['status'] ?? 'active',
        ));

        return response()->json([
            'message' => 'Tenant user created successfully.',
            'data' => $user->toArray(),
        ], 201);
    }

    public function update(Request $request, string $tenantUserCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],
            'nrc' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:100'],
            'role_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:20'],
            'update_key' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $user = $this->tenantUserService->update(new TenantUserUpdate(
            userId: $this->tenantUserService->resolveIdByCode($tenantUserCode),
            code: $tenantUserCode,
            updateKey: $validated['update_key'] ?? 0,
            name: $validated['name'] ?? null,
            nrc: $validated['nrc'] ?? null,
            email: $validated['email'] ?? null,
            phone: $validated['phone'] ?? null,
            address: $validated['address'] ?? null,
            roleId: $validated['role_id'] ?? null,
            status: $validated['status'] ?? null,
        ));

        return response()->json([
            'message' => 'Tenant user updated successfully.',
            'data' => $user->toArray(),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', PasswordRules::strong(), 'max:255', 'confirmed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $this->tenantUserService->changeCurrentUserPassword(
            currentPassword: $validated['current_password'],
            newPassword: $validated['password'],
        );

        return response()->json([
            'message' => 'Password changed successfully. Please login again.',
        ]);
    }

    public function resetPasswordToDefault(Request $request, string $tenantUserCode): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'logoutFromAll' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $this->tenantUserService->resetPasswordToDefault(
            tenantUserId: $this->tenantUserService->resolveIdByCode($tenantUserCode),
            logoutFromAll: (bool) ($validated['logoutFromAll'] ?? false),
        );

        return response()->json([
            'message' => 'Tenant user password reset to default successfully.',
        ]);
    }

    public function show(string $tenantUserCode): JsonResponse
    {
        $user = $this->tenantUserService->showByCode($tenantUserCode);

        return response()->json([
            'data' => $user->toArray(),
        ]);
    }

    public function updatePermissions(Request $request, string $tenantUserCode): JsonResponse
    {
        $rules = [];

        foreach (array_keys(config('tenant_permissions.codes', [])) as $permission) {
            $rules[$permission] = ['nullable', 'boolean'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $this->tenantUserService->updatePermissions($this->tenantUserService->resolveIdByCode($tenantUserCode), $validator->validated());

        return response()->json([
            'message' => 'Tenant user permissions updated successfully.',
            'data' => $user->toArray(),
        ]);
    }

    public function destroy(string $tenantUserCode): JsonResponse
    {
        $this->tenantUserService->delete($this->tenantUserService->resolveIdByCode($tenantUserCode));

        return response()->json([
            'message' => 'Tenant user deleted successfully.',
        ]);
    }
}
