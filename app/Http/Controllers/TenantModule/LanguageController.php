<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\AuthService;
use App\Services\TenantModule\TenantUserService;
use App\Utility\MessageCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    public function __construct(
        private TenantUserService $tenantUserService,
        private AuthService $authService
    ) {
    }

    public function change(Request $request)
    {
        $request->validate([
            'preferLang' => ['required', 'string', Rule::in(config('app.supported_locales', ['en', 'mm']))],
            'updateKey' => ['nullable', 'integer', 'min:0'],
        ]);

        $lang = $request->input('preferLang');
        $updateKey = (int) $request->input('updateKey');
        $user = $this->authService->getCurrentUser();

        $updatedUser = $this->tenantUserService->changePreferLanguageForCurrentUser($user, $lang, $updateKey);

        return $this->successResponse(
            $updatedUser->toArray(),
            $this->responseMessage(MessageCode::LanguageChangeSuccess),
        );
    }
}
