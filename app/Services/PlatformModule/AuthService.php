<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\PlatformUserRegister;
use App\DataObjects\ResponseObjects\PlatformUserDetail;
use App\Exceptions\AccountNotFound;
use App\Exceptions\EmailNotRegistered;
use App\Exceptions\InvalidCredential;
use App\Exceptions\LoginNotAllowed;
use App\Exceptions\UserNotLoggedIn;
use App\Mail\PlatformPasswordResetOtpMail;
use App\Mail\PlatformRegistrationVerificationMail;
use App\Models\PlatformModule\PlatformAdmin;
use App\Models\PlatformModule\PlatformUser;
use App\Repository\PlatformAdminRepository;
use App\Repository\PlatformUserRepository;
use App\Services\AuthLoginAttemptService;
use App\Services\TableIdGenerationService;
use App\Support\LogsServiceOperations;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class AuthService
{
    use LogsServiceOperations;

    /**
     * Create a new class instance.
     */
    private PlatformAdminRepository $adminRepository;

    private PlatformUserRepository $userRepository;

    public function __construct(
        PlatformAdminRepository $adminRepository,
        PlatformUserRepository $userRepository,
        private TableIdGenerationService $tableIdGenerationService,
        private AuthLoginAttemptService $loginAttemptService,
        private PlatformUserCredentialService $platformUserCredentialService,
    ) {
        $this->adminRepository = $adminRepository;
        $this->userRepository = $userRepository;
    }

    public function registerUser(PlatformUserRegister $request): PlatformUser
    {
        return DB::transaction(fn () => PlatformUser::query()->create([
            'code' => $this->tableIdGenerationService->generateForPlatform('platform_users', CarbonImmutable::now()),
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'status' => 'pending_verification',
        ]));
    }

    public function loginUser(string $email, string $password): PlatformUserDetail
    {
        $guard = 'platformuser';
        $user = $this->userRepository->findByEmail($email);

        if (! $user) {
            throw new EmailNotRegistered;
        }

        $this->loginAttemptService->ensureIsNotLocked($guard, $email);

        if (! Hash::check($password, $user->password)) {
            $this->loginAttemptService->recordFailedPassword($guard, $email);

            throw new InvalidCredential(null);
        }

        if ($user->status === 'pending_verification') {
            throw new InvalidCredential('Verify your email before logging in.');
        }

        if ($user->status !== 'active') {
            throw new LoginNotAllowed;
        }

        $this->loginAttemptService->clear($guard, $email);
        Auth::guard('platformadmin')->logout();
        Auth::guard($guard)->login($user);
        $this->applyUserLocale($user->prefer_lang ?? config('app.locale'));

        return new PlatformUserDetail($user->email, $user->name, $user->prefer_lang ?? config('app.locale'));
    }

    public function pendingVerificationLoginCandidate(string $email, string $password): ?PlatformUser
    {
        $guard = 'platformuser';
        $user = $this->userRepository->findByEmail($email);

        if (! $user || $user->status !== 'pending_verification') {
            return null;
        }

        $this->loginAttemptService->ensureIsNotLocked($guard, $email);

        if (! Hash::check($password, $user->password)) {
            return null;
        }

        $this->loginAttemptService->clear($guard, $email);

        return $user;
    }

    public function loginAdmin(string $email, string $password): PlatformAdmin
    {
        $guard = 'platformadmin';
        $admin = $this->adminRepository->findByEmail($email);

        if (! $admin) {
            throw new EmailNotRegistered;
        }

        $this->loginAttemptService->ensureIsNotLocked($guard, $email);

        if (! Hash::check($password, $admin->password)) {
            $this->loginAttemptService->recordFailedPassword($guard, $email);

            throw new InvalidCredential(null);
        }

        if ($admin->status !== 'active') {
            throw new LoginNotAllowed;
        }

        $this->loginAttemptService->clear($guard, $email);
        Auth::guard('platformuser')->logout();
        Auth::guard($guard)->login($admin);

        return $admin;
    }

    public function logout(string $guard): void
    {
        Auth::guard($guard)->logout();
    }

    public function requestOTP(string $email, bool $isAdmin): void
    {
        $this->runLoggedOperation(__METHOD__, function () use ($email, $isAdmin): void {
            $account = $this->resolveAccount($email, $isAdmin);
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $passwordConfig = $this->passwordConfig($isAdmin);

            DB::table($passwordConfig['table'])->updateOrInsert(
                ['email' => $email],
                [
                    'token' => Hash::make($otp),
                    'created_at' => now(),
                ],
            );

            Mail::to($account->email)->send((new PlatformPasswordResetOtpMail(
                otp: $otp,
                expiresInMinutes: $passwordConfig['expire'],
                recipientName: $account->name,
            ))->locale($this->mailLocaleFor($account)));
        });
    }

    public function requestRegistrationVerification(string $email): void
    {
        $this->runLoggedOperation(__METHOD__, function () use ($email): void {
            $account = $this->resolveAccount($email, false);
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $passwordConfig = $this->passwordConfig(false);

            DB::table('platform_user_email_verification_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => Hash::make($otp),
                    'created_at' => now(),
                    'consumed_at' => null,
                ],
            );

            Mail::to($account->email)->send((new PlatformRegistrationVerificationMail(
                otp: $otp,
                expiresInMinutes: $passwordConfig['expire'],
                recipientName: $account->name,
            ))->locale($this->mailLocaleFor($account)));
        });
    }

    public function verifyRegistrationOTP(string $email, string $otp): void
    {
        $account = $this->resolveAccount($email, false);
        $passwordConfig = $this->passwordConfig(false);
        $row = DB::table('platform_user_email_verification_tokens')->where('email', $email)->first();

        if (! $row || $row->consumed_at !== null || ! is_string($row->token) || ! Hash::check($otp, $row->token)) {
            throw new InvalidCredential('Invalid verification code.');
        }

        if (now()->diffInMinutes($row->created_at) > $passwordConfig['expire']) {
            DB::table('platform_user_email_verification_tokens')->where('email', $email)->delete();

            throw new InvalidCredential('Verification code expired.');
        }

        DB::transaction(function () use ($account, $email): void {
            $account->forceFill([
                'email_verified_at' => now(),
                'status' => 'active',
            ])->save();

            DB::table('platform_user_email_verification_tokens')
                ->where('email', $email)
                ->update(['consumed_at' => now()]);
        });
    }

    public function loginVerifiedRegistrationUser(string $email): PlatformUser
    {
        $user = $this->resolveAccount($email, false);

        if (! $user instanceof PlatformUser || $user->status !== 'active' || $user->email_verified_at === null) {
            throw new LoginNotAllowed;
        }

        Auth::guard('platformadmin')->logout();
        Auth::guard('platformuser')->login($user);
        $this->applyUserLocale($user->prefer_lang ?? config('app.locale'));

        return $user;
    }

    public function verifyOTP(string $email, string $otp, bool $isAdmin): void
    {
        $this->resolveAccount($email, $isAdmin);

        $passwordConfig = $this->passwordConfig($isAdmin);
        $row = DB::table($passwordConfig['table'])->where('email', $email)->first();

        if (! $row || ! is_string($row->token) || ! Hash::check($otp, $row->token)) {
            throw new InvalidCredential('Invalid OTP');
        }

        if (now()->diffInMinutes($row->created_at) > $passwordConfig['expire']) {
            DB::table($passwordConfig['table'])->where('email', $email)->delete();

            throw new InvalidCredential('OTP Expired');
        }
    }

    public function resetPassword(string $email, string $newPassword, bool $isAdmin): void
    {
        $account = $this->resolveAccount($email, $isAdmin);

        DB::transaction(function () use ($account, $email, $isAdmin, $newPassword): void {
            if ($account instanceof PlatformUser) {
                $this->platformUserCredentialService->replacePassword((int) $account->id, $newPassword);
            } else {
                $this->adminRepository->updatePasswordCredentials($account, Hash::make($newPassword));
            }

            DB::table($this->passwordConfig($isAdmin)['table'])->where('email', $email)->delete();
        });
    }

    public function changePassword(string $currentPassword, string $newPassword, bool $isAdmin): void
    {
        $guard = $isAdmin ? 'platformadmin' : 'platformuser';
        $account = Auth::guard($guard)->user();

        if (! $account) {
            throw new AuthenticationException;
        }

        if (! Hash::check($currentPassword, $account->password)) {
            throw new InvalidCredential(null);
        }
        if ($isAdmin) {
            $this->adminRepository->updatePasswordCredentials($account, Hash::make($newPassword));
        } else {
            $this->platformUserCredentialService->replacePassword((int) $account->id, $newPassword);
        }
    }

    protected function resolveAccount(string $email, bool $isAdmin): PlatformAdmin|PlatformUser
    {
        $account = $isAdmin
            ? $this->adminRepository->findByEmail($email)
            : $this->userRepository->findByEmail($email);

        if (! $account) {
            throw new AccountNotFound(null);
        }

        return $account;
    }

    protected function passwordConfig(bool $isAdmin): array
    {
        return config('auth.passwords.'.($isAdmin ? 'platformadmins' : 'platformusers'));
    }

    public function getCurrentUser(?string $guard)
    {
        if ($guard != null) {
            $account = Auth::guard($guard)->user();
            if (! $account) {
                throw new UserNotLoggedIn(null);
            }
        } else {
            $account = Auth::guard('platformadmin')->user();
            if (! $account) {
                $account = Auth::guard('platformuser')->user();
                if (! $account) {
                    throw new UserNotLoggedIn(null);
                }
            }
        }

        return $account;
    }

    protected function applyUserLocale(string $locale): void
    {
        if (! in_array($locale, config('app.supported_locales', []), true)) {
            $locale = config('app.locale');
        }

        app()->setLocale($locale);

        if (request()->hasSession()) {
            session()->put('locale', $locale);
        }
    }

    protected function mailLocaleFor(PlatformAdmin|PlatformUser $account): string
    {
        $locale = $account instanceof PlatformUser
            ? ($account->prefer_lang ?? config('app.locale'))
            : config('app.locale');

        return in_array($locale, config('app.supported_locales', []), true)
            ? $locale
            : config('app.locale');
    }
}
