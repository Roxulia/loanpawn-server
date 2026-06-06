<?php

namespace App\Http\Controllers\PlatformModule;

use App\DataObjects\RequestObjects\PlatformUserRegister;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Rules\PasswordRules;
use App\Services\PlatformModule\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const RESET_EMAIL_KEY = 'platform_user_reset_email';
    private const RESET_VERIFIED_KEY = 'platform_user_reset_verified';
    private const RESET_RESEND_KEY = 'platform_user_reset_resend_at';
    private const RESET_CODE_SENT_KEY = 'platform_user_reset_code_sent';
    private const RESET_RESEND_SECONDS = 90;
    private const REGISTER_EMAIL_KEY = 'platform_user_register_email';
    private const REGISTER_RESEND_KEY = 'platform_user_register_resend_at';

    public function __construct(
        private AuthService $authService,
    ) {
    }

    public function showAdminLogin(): View|RedirectResponse
    {
        if (Auth::guard('platformadmin')->check()) {
            return redirect()->route('admin.dashboard');
        }
        $isAdmin = true;

        return view('platform.auth.login', compact('isAdmin'));
    }

    public function showUserLogin(): View|RedirectResponse
    {
        if (Auth::guard('platformuser')->check()) {
            return redirect()->route('platform.dashboard');
        }
        $isAdmin = false;

        return view('platform.auth.login', compact('isAdmin'));
    }

    public function loginUser(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'required|string|max:255',
                'password' => 'required|string|max:255',
            ],
            [
                'email.required' => 'Email is required',
                'password.required' => 'Password is required',
            ]
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $pendingVerificationUser = $this->authService->pendingVerificationLoginCandidate($validated['email'], $validated['password']);

        if ($pendingVerificationUser !== null) {
            $this->authService->requestRegistrationVerification($pendingVerificationUser->email);
            session([
                self::REGISTER_EMAIL_KEY => $pendingVerificationUser->email,
                self::REGISTER_RESEND_KEY => now()->addSeconds(self::RESET_RESEND_SECONDS)->timestamp,
            ]);

            return $this->errorResponse(
                'Verify your email before logging in.',
                [
                    'errors' => [
                        'email' => ['Verify your email before logging in.'],
                    ],
                    'redirect' => route('platform.register.verify', ['email' => $pendingVerificationUser->email]),
                ],
                403,
            );
        }

        $user = $this->authService->loginUser($validated['email'], $validated['password']);

        return $this->successResponse(
            [
                ...$user->toArray(),
                'redirect' => route('platform.dashboard'),
            ],
            'Login success',
        );
    }

    public function loginAdmin(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'email' => 'required|string|max:255',
                'password' => 'required|string|max:255',
            ],
            [
                'email.required' => 'Email is required',
                'password.required' => 'Password is required',
            ]
        );

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $user = $this->authService->loginAdmin($validated['email'], $validated['password']);
        $redirect = Hash::check(config('auth.seeded_platform_admin_password', 'password'), $user->password)
            ? route('admin.password.change')
            : route('admin.dashboard');

        return $this->successResponse(
            [
                ...$user->toArray(),
                'redirect' => $redirect,
            ],
            'Login success',
        );
    }

    public function logoutUser(Request $request): RedirectResponse
    {
        $this->authService->logout('platformuser');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login.show');
    }

    public function logoutAdmin(Request $request): RedirectResponse
    {
        $this->authService->logout('platformadmin');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login.show');
    }

    public function showAdminChangePassword(): View|RedirectResponse
    {
        if (! Auth::guard('platformadmin')->check()) {
            return redirect()->route('admin.login.show');
        }

        return view('platform.auth.change-password', ['isAdmin' => true]);
    }

    public function changeAdminPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', PasswordRules::strong(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $this->authService->changePassword($validated['current_password'], $validated['password'], true);

        return $this->successResponse(
            ['redirect' => route('admin.dashboard')],
            'Password changed successfully.',
        );
    }

    public function showRegister(): View
    {
        return view('platform.auth.register');
    }

    public function showRegisterVerify(Request $request): View
    {
        return view('platform.auth.register-verify', [
            'email' => session(self::REGISTER_EMAIL_KEY, $request->query('email', '')),
            'resendAvailableAt' => (int) session(self::REGISTER_RESEND_KEY, 0),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('platform_users', 'email')],
            'password' => ['required', 'string', PasswordRules::strong(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();

        $payload = new PlatformUserRegister(
            email: $validated['email'],
            name: $validated['name'],
            password: $validated['password'],
        );

        $user = $this->authService->registerUser($payload);
        $this->authService->requestRegistrationVerification($user->email);
        session([
            self::REGISTER_EMAIL_KEY => $user->email,
            self::REGISTER_RESEND_KEY => now()->addSeconds(self::RESET_RESEND_SECONDS)->timestamp,
        ]);

        return $this->successResponse(
            ['redirect' => route('platform.register.verify', ['email' => $user->email])],
            'User registered successfully. Check your email for the verification code.',
        );
    }

    public function sendRegisterVerificationCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', Rule::exists('platform_users', 'email')],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $resendAvailableAt = (int) session(self::REGISTER_RESEND_KEY, 0);

        if ($resendAvailableAt > now()->timestamp) {
            $remaining = $resendAvailableAt - now()->timestamp;

            return $this->errorResponse(
                'You can request a new code in '.$remaining.' seconds.',
                [
                    'errors' => [
                        'email' => ['You can request a new code in '.$remaining.' seconds.'],
                    ],
                    'retry_after' => $remaining,
                ],
                429,
            );
        }

        $validated = $validator->validated();
        $this->authService->requestRegistrationVerification($validated['email']);
        session([
            self::REGISTER_EMAIL_KEY => $validated['email'],
            self::REGISTER_RESEND_KEY => now()->addSeconds(self::RESET_RESEND_SECONDS)->timestamp,
        ]);

        return $this->successResponse(
            [
                'email' => $validated['email'],
                'resendAvailableAt' => (int) session(self::REGISTER_RESEND_KEY),
            ],
            'Verification code sent to '.$validated['email'].'.',
        );
    }

    public function verifyRegisterCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', Rule::exists('platform_users', 'email')],
            'otp' => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $validated = $validator->validated();
        $this->authService->verifyRegistrationOTP($validated['email'], $validated['otp']);
        session()->forget([self::REGISTER_EMAIL_KEY, self::REGISTER_RESEND_KEY]);

        return $this->successResponse(
            ['redirect' => route('platform.login.show')],
            'Email verified. Please login with your new account.',
        );
    }

    public function showForgotPassword(Request $request): View
    {
        return view('platform.auth.forgot-password', [
            'email' => session(self::RESET_EMAIL_KEY, $request->query('email', '')),
            'isCodeSent' => (bool) session(self::RESET_CODE_SENT_KEY, false),
            'isOtpVerified' => (bool) session(self::RESET_VERIFIED_KEY, false),
            'resendAvailableAt' => (int) session(self::RESET_RESEND_KEY, 0),
        ]);
    }

    public function sendResetCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', Rule::exists('platform_users', 'email')],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        $resendAvailableAt = (int) session(self::RESET_RESEND_KEY, 0);

        if ($resendAvailableAt > now()->timestamp) {
            $remaining = $resendAvailableAt - now()->timestamp;

            return $this->errorResponse(
                'You can request a new code in '.$remaining.' seconds.',
                [
                    'errors' => [
                        'email' => ['You can request a new code in '.$remaining.' seconds.'],
                    ],
                    'retry_after' => $remaining,
                ],
                429,
            );
        }
        $this->authService->requestOTP($request['email'], false);
        session([
            self::RESET_EMAIL_KEY => $request['email'],
            self::RESET_VERIFIED_KEY => false,
            self::RESET_CODE_SENT_KEY => true,
            self::RESET_RESEND_KEY => now()->addSeconds(self::RESET_RESEND_SECONDS)->timestamp,
        ]);

        return $this->successResponse(
            [
                'email' => $request['email'],
                'isCodeSent' => true,
                'isOtpVerified' => false,
                'resendAvailableAt' => (int) session(self::RESET_RESEND_KEY),
            ],
            'Verification code sent to '.$request['email'].'.',
        );
    }

    public function verifyResetCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', Rule::exists('platform_users', 'email')],
            'otp' => ['required', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $this->authService->verifyOTP($request['email'], $request['otp'], false);

        session([
            self::RESET_EMAIL_KEY => $request['email'],
            self::RESET_VERIFIED_KEY => true,
            self::RESET_CODE_SENT_KEY => true,
        ]);

        return $this->successResponse(
            [
                'email' => $request['email'],
                'isCodeSent' => true,
                'isOtpVerified' => true,
                'otpVerifiedNow' => true,
            ],
            'OTP verified.',
        );
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', Rule::exists('platform_users', 'email')],
            'password' => ['required', 'string', PasswordRules::strong(), 'confirmed'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }
        $verifiedEmail = session(self::RESET_EMAIL_KEY);
        $isVerified = (bool) session(self::RESET_VERIFIED_KEY, false);

        if (! $isVerified || $verifiedEmail !== $request['email']) {
            return $this->errorResponse(
                'Verify OTP before resetting the password.',
                [
                    'errors' => [
                        'password' => ['Verify OTP before resetting the password.'],
                    ],
                ],
                400,
            );
        }
        $this->authService->resetPassword($request['email'], $request['password'], false);
        $this->clearResetSession();

        return $this->successResponse(
            ['redirect' => route('platform.login.show')],
            'Password reset completed. Please login with your new password.',
        );
    }

    public function cancelReset(): JsonResponse
    {
        $this->clearResetSession();

        return $this->successResponse(
            ['redirect' => route('platform.login.show')],
            'Password reset flow canceled.',
        );
    }

    private function clearResetSession(): void
    {
        session()->forget([
            self::RESET_EMAIL_KEY,
            self::RESET_VERIFIED_KEY,
            self::RESET_RESEND_KEY,
            self::RESET_CODE_SENT_KEY,
        ]);
    }
}
