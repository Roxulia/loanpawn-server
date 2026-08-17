<?php

namespace Tests\Feature\TenantModule;

use App\Events\TenantNotificationCreated;
use App\Exceptions\InvalidTenantRequest;
use App\Models\CoreModule\Currency;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\ReportingCurrencyRecalculation;
use App\Repository\TenantUserNotificationRepository;
use App\Services\TenantModule\TenantUserNotificationService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class TenantUserNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_notifications_are_scoped_to_the_current_tenant_user_and_can_be_marked_read(): void
    {
        [$tenant, $user] = $this->tenantUser('first');
        [$otherTenant, $otherUser] = $this->tenantUser('second');
        $repository = app(TenantUserNotificationRepository::class);
        $first = $repository->create($this->notificationData($tenant, $user));
        $other = $repository->create($this->notificationData($otherTenant, $otherUser));
        app(TenantContext::class)->set($tenant->id);
        Auth::guard('tenantuser')->login($user);
        $service = app(TenantUserNotificationService::class);

        $page = $service->list(20);
        $this->assertSame(1, $page->total);
        $this->assertSame(1, $page->unreadCount);
        $this->assertSame($first->id, $page->items[0]['id']);
        $this->assertNotNull($service->markRead($first->id)->read_at);

        $this->expectException(InvalidTenantRequest::class);
        $service->markRead($other->id);
    }

    public function test_reporting_currency_notification_is_persisted_and_broadcast_after_commit(): void
    {
        Event::fake([TenantNotificationCreated::class]);
        [$tenant, $user] = $this->tenantUser('broadcast');
        $this->seed(CurrencySeeder::class);
        $mmk = Currency::query()->where('code', 'MMK')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $recalculation = ReportingCurrencyRecalculation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'initiated_by_tenant_user_id' => $user->id,
            'previous_reporting_currency_id' => $mmk->id,
            'requested_reporting_currency_id' => $usd->id,
            'window_start' => '2026-06-01',
            'window_end' => '2026-08-17',
            'status' => 'queued',
        ])->load(['previousReportingCurrency', 'requestedReportingCurrency']);

        DB::transaction(fn () => app(TenantUserNotificationService::class)->recordReportingCurrencyStatus($recalculation));

        $this->assertDatabaseHas('tenant_user_notifications', [
            'tenant_id' => $tenant->id,
            'tenant_user_id' => $user->id,
            'status' => 'queued',
        ]);
        Event::assertDispatched(TenantNotificationCreated::class);
    }

    public function test_rolled_back_status_notification_is_not_persisted_or_broadcast(): void
    {
        Event::fake([TenantNotificationCreated::class]);
        [$tenant, $user] = $this->tenantUser('rollback');
        $this->seed(CurrencySeeder::class);
        $mmk = Currency::query()->where('code', 'MMK')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $recalculation = ReportingCurrencyRecalculation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'initiated_by_tenant_user_id' => $user->id,
            'previous_reporting_currency_id' => $mmk->id,
            'requested_reporting_currency_id' => $usd->id,
            'window_start' => '2026-06-01',
            'window_end' => '2026-08-17',
            'status' => 'processing',
        ])->load(['previousReportingCurrency', 'requestedReportingCurrency']);

        try {
            DB::transaction(function () use ($recalculation): void {
                app(TenantUserNotificationService::class)->recordReportingCurrencyStatus($recalculation);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('tenant_user_notifications', 0);
        Event::assertNotDispatched(TenantNotificationCreated::class);
    }

    public function test_cleanup_removes_notifications_older_than_thirty_days(): void
    {
        CarbonImmutable::setTestNow('2026-08-17 12:00:00');
        [$tenant, $user] = $this->tenantUser('cleanup');
        $repository = app(TenantUserNotificationRepository::class);
        $old = $repository->create($this->notificationData($tenant, $user));
        $old->timestamps = false;
        $old->forceFill(['created_at' => CarbonImmutable::now()->subDays(31)])->save();
        $repository->create($this->notificationData($tenant, $user));

        $this->assertSame(1, app(TenantUserNotificationService::class)->purgeExpired());
        $this->assertDatabaseCount('tenant_user_notifications', 1);
    }

    private function notificationData(Tenant $tenant, TenantUser $user): array
    {
        return [
            'tenant_id' => $tenant->id,
            'tenant_user_id' => $user->id,
            'type' => 'reporting_currency_recalculation',
            'status' => 'queued',
            'data' => [
                'previous_currency' => ['id' => 1, 'code' => 'MMK'],
                'requested_currency' => ['id' => 2, 'code' => 'USD'],
                'missing_rate_count' => 0,
            ],
        ];
    }

    private function tenantUser(string $code): array
    {
        $owner = PlatformUser::query()->create([
            'code' => 'PU'.strtoupper($code),
            'name' => 'Owner',
            'email' => "{$code}-owner@example.com",
            'phone' => '09'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
        ]);
        $tenant = Tenant::query()->create([
            'platform_user_id' => $owner->id,
            'name' => "{$code} Tenant",
            'tenant_code' => $code,
            'subdomain' => $code,
            'status' => 'active',
        ]);
        $user = TenantUser::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'code' => 'TU'.strtoupper($code),
            'username' => substr($code, 0, 8),
            'name' => 'Tenant User',
            'nrc' => "NRC-{$code}",
            'email' => "{$code}@example.com",
            'phone' => '08'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return [$tenant, $user];
    }
}
