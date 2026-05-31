<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, fn () => new TenantContext());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for('tenant-api', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'no-tenant';
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(60)->by($tenantId.':'.($userId ?? $request->ip()));
        });

        RateLimiter::for('online-sync', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'no-tenant';
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(6)->by($tenantId.':'.($userId ?? $request->ip()));
        });
    }
}
