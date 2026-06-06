<?php

namespace App\Http\Controllers\TenantModule;

use App\DataObjects\RequestObjects\TenantUserPublicLogin;
use App\DataObjects\RequestObjects\TenantUserSubdomainLogin;
use App\Http\Controllers\Controller;
use App\Services\TenantModule\AuthService;
use App\Utility\MessageCode;
use App\Services\TenantModule\TenantSsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private TenantSsoService $tenantSsoService,
    ) {
    }

    public function loginPublicSpa(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_code' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $session = $this->authService->loginFromPublicSpa(new TenantUserPublicLogin(
            tenantCode: $validated['tenant_code'],
            email: $validated['email'],
            password: $validated['password'],
        ));

        return $this->successResponse($session->toArray(), $this->responseMessage(MessageCode::TenantUserLoginSuccess));
    }

    public function loginSubdomainSpa(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $session = $this->authService->loginFromSubdomainSpa(new TenantUserSubdomainLogin(
            email: $validated['email'],
            password: $validated['password'],
        ));

        return $this->successResponse($session->toArray(), $this->responseMessage(MessageCode::TenantUserLoginSuccess));
    }

    public function consumeSso(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'tenant_code' => ['required', 'string', 'max:32'],
            'token' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $session = $this->tenantSsoService->consume($validated['tenant_code'], $validated['token']);

        return $this->successResponse($session->toArray(), $this->responseMessage(MessageCode::TenantUserSsoLoginSuccess));
    }

    public function me(): JsonResponse
    {
        $user = $this->authService->getCurrentUser();

        return $this->successResponse($user->toArray());
    }

    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return $this->successResponse(message: $this->responseMessage(MessageCode::TenantUserLogoutSuccess));
    }
}
