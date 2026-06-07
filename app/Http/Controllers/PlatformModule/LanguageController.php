<?php

namespace App\Http\Controllers\PlatformModule;

use App\Http\Controllers\Controller;
use App\Services\PlatformModule\AuthService;
use App\Services\PlatformModule\PlatformUserService;
use App\Utility\MessageCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LanguageController extends Controller
{
    public function __construct(
        private PlatformUserService $platformUserService,
        private AuthService $authService
    ) {
    }

    public function edit(): View
    {
        return view('platform.settings', [
            'user' => $this->authService->getCurrentUser('platformuser'),
            'supportedLocales' => config('app.supported_locales', ['en', 'mm']),
        ]);
    }

    public function change(Request $request)
    {
        $request->validate([
            'lang' => ['required', 'string', Rule::in(config('app.supported_locales', []))],
        ]);

        $lang = $request->input('lang');
        $user = $this->authService->getCurrentUser('platformuser');

        $updatedUser = $this->platformUserService->changePreferLanguageForCurrentUser($user, $lang);
        $message = $this->responseMessage(MessageCode::LanguageChangeSuccess);

        if (! $request->expectsJson()) {
            return back()->with('status', $message);
        }

        return $this->successResponse(
            ['preferLang' => $updatedUser->prefer_lang],
            $message,
        );
    }
}
