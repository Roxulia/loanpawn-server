<?php

namespace App\Services\TenantModule;

use App\DataObjects\ResponseObjects\TenantUserNotificationListPage;
use App\Events\TenantNotificationCreated;
use App\Exceptions\InvalidTenantRequest;
use App\Models\ReportingCurrencyRecalculation;
use App\Models\TenantUserNotification;
use App\Repository\TenantUserNotificationRepository;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

class TenantUserNotificationService
{
    public function __construct(
        private TenantUserNotificationRepository $repository,
        private TenantContext $tenantContext,
    ) {}

    public function list(int $perPage): TenantUserNotificationListPage
    {
        [$tenantId, $tenantUserId] = $this->currentIdentity();
        $page = $this->repository->paginateForUser($tenantId, $tenantUserId, $perPage);

        return TenantUserNotificationListPage::fromPaginator(
            $page,
            $this->repository->unreadCount($tenantId, $tenantUserId),
        );
    }

    public function markRead(string $id): TenantUserNotification
    {
        [$tenantId, $tenantUserId] = $this->currentIdentity();
        $notification = $this->repository->findForUser($id, $tenantId, $tenantUserId)
            ?? throw new InvalidTenantRequest;

        return $this->repository->markRead($notification);
    }

    public function markAllRead(): void
    {
        [$tenantId, $tenantUserId] = $this->currentIdentity();
        $this->repository->markAllRead($tenantId, $tenantUserId);
    }

    public function recordReportingCurrencyStatus(ReportingCurrencyRecalculation $recalculation): ?TenantUserNotification
    {
        if ($recalculation->initiated_by_tenant_user_id === null) {
            return null;
        }

        $notification = $this->repository->create([
            'tenant_id' => (int) $recalculation->tenant_id,
            'tenant_user_id' => (int) $recalculation->initiated_by_tenant_user_id,
            'reporting_currency_recalculation_id' => (int) $recalculation->id,
            'type' => 'reporting_currency_recalculation',
            'status' => $recalculation->status,
            'data' => [
                'previous_currency' => [
                    'id' => (int) $recalculation->previousReportingCurrency->id,
                    'code' => $recalculation->previousReportingCurrency->code,
                ],
                'requested_currency' => [
                    'id' => (int) $recalculation->requestedReportingCurrency->id,
                    'code' => $recalculation->requestedReportingCurrency->code,
                ],
                'missing_rate_count' => count($recalculation->missing_rates ?? []),
            ],
        ]);

        event(new TenantNotificationCreated($notification));

        return $notification;
    }

    public function purgeExpired(): int
    {
        return $this->repository->deleteCreatedBefore(CarbonImmutable::now()->subDays(30));
    }

    private function currentIdentity(): array
    {
        $tenantId = $this->tenantContext->id() ?? throw new InvalidTenantRequest;
        $tenantUserId = Auth::guard('tenantuser')->id() ?? Auth::id() ?? throw new InvalidTenantRequest;

        return [(int) $tenantId, (int) $tenantUserId];
    }
}
