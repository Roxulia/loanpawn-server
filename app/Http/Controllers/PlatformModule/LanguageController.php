<?php

namespace App\Http\Controllers\PlatformModule;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\AuthService;
use App\Services\PlatformModule\PlatformUserService;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    //
    public function __construct(
        private PlatformUserService $platformUserService,
        private AuthService $authService
    )
    {

    }
    public function change(Request $request)
    {
        $request->validate([
            'lang' => 'required|string|in:en,mm'
        ]);
        $lang = $request->input('lang');
        $user = $this->authService->getCurrentUser('platformuser');

        $this->platformUserService->changePreferLanguageForCurrentUser($user, $lang);

        return $this->successResponse(null, 'Language preference updated successfully.',201);
    }
}
