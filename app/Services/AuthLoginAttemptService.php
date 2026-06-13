<?php

namespace App\Services;

use App\Exceptions\LoginRetryLocked;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthLoginAttemptService
{
    public function ensureIsNotLocked(string $guard, string $email, ?string $scope = null): void
    {
        $key = $this->lockKey($guard, $email, $scope);

        if (! RateLimiter::tooManyAttempts($key, 1)) {
            return;
        }

        throw new LoginRetryLocked(RateLimiter::availableIn($key));
    }

    public function recordFailedPassword(string $guard, string $email, ?string $scope = null): void
    {
        $attemptKey = $this->attemptKey($guard, $email, $scope);
        $attempts = RateLimiter::hit($attemptKey, $this->attemptWindowSeconds());

        if ($attempts < $this->maxAttempts()) {
            return;
        }

        RateLimiter::clear($attemptKey);
        RateLimiter::hit($this->lockKey($guard, $email, $scope), $this->lockoutSeconds());

        throw new LoginRetryLocked($this->lockoutSeconds());
    }

    public function clear(string $guard, string $email, ?string $scope = null): void
    {
        RateLimiter::clear($this->attemptKey($guard, $email, $scope));
        RateLimiter::clear($this->lockKey($guard, $email, $scope));
    }

    private function attemptKey(string $guard, string $email, ?string $scope): string
    {
        return $this->key('attempts', $guard, $email, $scope);
    }

    private function lockKey(string $guard, string $email, ?string $scope): string
    {
        return $this->key('lock', $guard, $email, $scope);
    }

    private function key(string $type, string $guard, string $email, ?string $scope): string
    {
        $parts = [
            'auth-login',
            $type,
            Str::lower($guard),
            $scope !== null && $scope !== '' ? Str::lower($scope) : 'global',
            sha1(Str::lower($email)),
        ];

        return implode(':', $parts);
    }

    private function maxAttempts(): int
    {
        return (int) config('auth.login_attempts.max_attempts', 3);
    }

    private function attemptWindowSeconds(): int
    {
        return (int) config('auth.login_attempts.attempt_window_seconds', 60);
    }

    private function lockoutSeconds(): int
    {
        return (int) config('auth.login_attempts.lockout_seconds', 300);
    }
}
