<?php

namespace App\Http\Controllers\TenantModule;

use App\Http\Controllers\Controller;
use App\Services\TenantModule\AuthService;
use App\Services\TenantModule\TenantUserService;
use Illuminate\Http\Request;
use Mpdf\Tag\A;

class LanguageController extends Controller
{
    public function __construct(
        private TenantUserService $tenantUserService,
        private AuthService $authService
    )
    {

    }
    public function change(Request $request)
    {
        $request->validate([
            'lang' => 'required|string|in:en,mm',
            'update_key' => 'nullable|integer'
        ]);
        $lang = $request->input('lang');
        $updateKey = $request->input('update_key');
        $user = $this->authService->getCurrentUser();

        $this->tenantUserService->changePreferLanguageForCurrentUser($user, $lang, $updateKey);

        return $this->successResponse(null, 'Language preference updated successfully.',201);
    }
}
