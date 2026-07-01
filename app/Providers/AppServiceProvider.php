<?php

namespace App\Providers;

use App\Models\PlatformModule\ManualPaymentAttachment;
use App\Models\PlatformModule\PlatformSupportTicket;
use App\Models\PlatformModule\PlatformSupportTicketAttachment;
use App\Models\PlatformModule\TenantRequest;
use App\Observers\ManualPaymentAttachmentObserver;
use App\Observers\PlatformSupportTicketAttachmentObserver;
use App\Observers\PlatformSupportTicketObserver;
use App\Observers\TenantRequestObserver;
use App\Support\TenantContext;
use App\Support\RedisAvailability;
use App\Support\TenantScopedCacheKeys;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
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
        $this->app->singleton(RedisAvailability::class, fn () => new RedisAvailability());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        TenantRequest::observe(TenantRequestObserver::class);
        ManualPaymentAttachment::observe(ManualPaymentAttachmentObserver::class);
        PlatformSupportTicket::observe(PlatformSupportTicketObserver::class);
        PlatformSupportTicketAttachment::observe(PlatformSupportTicketAttachmentObserver::class);

        /** @var RedisAvailability $redisAvailability */
        $redisAvailability = app(RedisAvailability::class);

        app(TenantScopedCacheKeys::class)->configureDefaultCacheStore();
        Queue::setDefaultDriver($redisAvailability->selectedQueueConnection());

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
