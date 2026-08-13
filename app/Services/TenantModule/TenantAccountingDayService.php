<?php

namespace App\Services\TenantModule;

use App\DataObjects\RequestObjects\AccountingDayScheduleUpdate;
use App\DataObjects\ResponseObjects\AccountingDayResource;
use App\DataObjects\ResponseObjects\AccountingDayScheduleResource;
use App\DataObjects\ResponseObjects\DefaultDataListPage;
use App\Enums\AccountingDayClosingSource;
use App\Enums\AccountingDayOpeningSource;
use App\Enums\AccountingDayStatus;
use App\Exceptions\AccountingDayClosed;
use App\Exceptions\InvalidTenantRequest;
use App\Models\TenantAccountingDay;
use App\Repository\TenantAccountingDayRepository;
use App\Services\BaseTenantService;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TenantAccountingDayService extends BaseTenantService
{
    public function __construct(
        private TenantAccountingDayRepository $repository,
        private AccountingDayBusinessClock $clock,
        private TenantUserPermissionService $permissionService,
        private TenantLicenseService $licenseService,
    ) {}

    public function current(): ?AccountingDayResource
    {
        $this->permissionService->authorizeAccountingList();
        $tenantId = $this->resolveCurrentTenantId();
        $day = $this->repository->findForTenantDate($tenantId, $this->clock->now($tenantId)->toDateString());

        return $day === null ? null : AccountingDayResource::fromModel($day);
    }

    public function list(int $perPage = 15): DefaultDataListPage
    {
        $this->permissionService->authorizeAccountingList();
        $page = $this->repository->paginate($perPage);
        $page->through(fn (TenantAccountingDay $day) => AccountingDayResource::fromModel($day)->toArray());

        return DefaultDataListPage::fromPaginator($page);
    }

    public function summary(string $businessDate): AccountingDayResource
    {
        $this->permissionService->authorizeAccountingList();
        $day = $this->repository->findForTenantDate($this->resolveCurrentTenantId(), $businessDate);

        if ($day === null) {
            throw new InvalidTenantRequest('Accounting day was not found.');
        }

        return AccountingDayResource::fromModel($day);
    }

    public function openCurrent(): AccountingDayResource
    {
        $this->permissionService->authorizeAccountingDayOpen();
        $tenantId = $this->resolveCurrentTenantId();
        $day = $this->ensureOpenForTenant(
            $tenantId,
            AccountingDayOpeningSource::Manual,
            Auth::guard('tenantuser')->id(),
        );

        return AccountingDayResource::fromModel($day);
    }

    public function closeCurrent(): AccountingDayResource
    {
        $this->permissionService->authorizeAccountingDayClose();
        $tenantId = $this->resolveCurrentTenantId();
        $now = $this->clock->now($tenantId);

        $day = DB::transaction(function () use ($tenantId, $now): TenantAccountingDay {
            $this->repository->lockTenant($tenantId);
            $day = $this->repository->findForTenantDate($tenantId, $now->toDateString(), true);

            if ($day === null || $day->status === AccountingDayStatus::NotOpened) {
                throw new InvalidTenantRequest('The current accounting day is not open.');
            }

            return $this->closeLockedDay(
                $day,
                AccountingDayClosingSource::Manual,
                $now->utc(),
                Auth::guard('tenantuser')->id(),
                false,
            );
        });

        return AccountingDayResource::fromModel($day);
    }

    public function ensureOpenForTransaction(?int $createdBy = null): TenantAccountingDay
    {
        return $this->ensureOpenForTenant(
            $this->resolveCurrentTenantId(),
            AccountingDayOpeningSource::FirstTransaction,
            $createdBy,
        );
    }

    public function currentBusinessDate(): string
    {
        $tenantId = $this->resolveCurrentTenantId();

        return $this->clock->now($tenantId)->toDateString();
    }

    public function assertDayEditable(?TenantAccountingDay $day): void
    {
        if ($day === null || $day->status !== AccountingDayStatus::Open) {
            throw new AccountingDayClosed;
        }
    }

    public function isDayEditable(?TenantAccountingDay $day): bool
    {
        return $day?->status === AccountingDayStatus::Open;
    }

    public function schedule(): AccountingDayScheduleResource
    {
        $tenantId = $this->resolveCurrentTenantId();
        $this->authorizeScheduleManagement($tenantId);

        return $this->scheduleResource($tenantId);
    }

    public function updateSchedule(AccountingDayScheduleUpdate $request): AccountingDayScheduleResource
    {
        $tenantId = $this->resolveCurrentTenantId();
        $this->authorizeScheduleManagement($tenantId);

        DB::transaction(function () use ($tenantId, $request): void {
            foreach ($request->days as $day) {
                $this->repository->upsertSchedule($tenantId, $day);
            }
        });

        return $this->scheduleResource($tenantId);
    }

    public function processAutomation(): void
    {
        foreach ($this->repository->automationTenantIds() as $tenantId) {
            try {
                $tenantId = (int) $tenantId;
                $now = $this->clock->now($tenantId);
                $this->closePreviousDayAtMidnight($tenantId, $now);

                if (! $this->licenseService->tenantHasFeature($tenantId, 'automatic_open_close')) {
                    continue;
                }

                $schedule = $this->repository->scheduleForWeekday($tenantId, $now->dayOfWeek);

                if ($schedule === null || ! $schedule->is_enabled) {
                    continue;
                }

                if ($now->format('H:i:s') >= $schedule->open_time) {
                    try {
                        $this->ensureOpenForTenant($tenantId, AccountingDayOpeningSource::Scheduled, null);
                    } catch (AccountingDayClosed) {
                        // A closed business date is intentionally never reopened.
                    }
                }

                if ($now->format('H:i:s') >= $schedule->close_time) {
                    $this->closeCurrentForAutomation($tenantId, $now, $schedule->close_time);
                }
            } catch (Throwable $exception) {
                Log::error('Accounting-day automation failed for tenant.', [
                    'tenant_id' => $tenantId,
                    'exception' => $exception,
                ]);
            }
        }
    }

    public function assertTimezoneChangeAllowed(): void
    {
        $latest = $this->repository->latestForTenant($this->resolveCurrentTenantId());

        if ($latest !== null && in_array($latest->status, [AccountingDayStatus::Open, AccountingDayStatus::Closing], true)) {
            throw new InvalidTenantRequest('Close the current accounting day before changing the tenant timezone.');
        }
    }

    private function ensureOpenForTenant(int $tenantId, AccountingDayOpeningSource $source, ?int $openedBy): TenantAccountingDay
    {
        return DB::transaction(function () use ($tenantId, $source, $openedBy): TenantAccountingDay {
            $this->repository->lockTenant($tenantId);
            $now = $this->clock->now($tenantId);
            $businessDate = $now->toDateString();
            $latest = $this->repository->latestForTenant($tenantId, true);

            if ($latest !== null && $latest->business_date->toDateString() > $businessDate) {
                throw new InvalidTenantRequest('The tenant timezone conflicts with existing accounting-day history.');
            }

            if ($latest !== null && $latest->business_date->toDateString() < $businessDate) {
                if ($latest->status !== AccountingDayStatus::Closed) {
                    $boundary = CarbonImmutable::parse($latest->business_date->toDateString(), $latest->timezone)->addDay()->startOfDay()->utc();
                    $latest = $this->closeLockedDay($latest, AccountingDayClosingSource::CatchUp, $boundary, null, true);
                }

                $cursor = CarbonImmutable::parse($latest->business_date)->addDay();
                $target = CarbonImmutable::parse($businessDate);

                while ($cursor->lessThan($target)) {
                    $emptyDay = $this->repository->create([
                        'tenant_id' => $tenantId,
                        'business_date' => $cursor->toDateString(),
                        'timezone' => $this->clock->timezone($tenantId),
                        'status' => AccountingDayStatus::NotOpened,
                    ]);
                    $this->closeLockedDay(
                        $emptyDay,
                        AccountingDayClosingSource::CatchUp,
                        CarbonImmutable::parse($cursor->toDateString(), $emptyDay->timezone)->addDay()->startOfDay()->utc(),
                        null,
                        true,
                    );
                    $cursor = $cursor->addDay();
                }
            }

            $day = $this->repository->findForTenantDate($tenantId, $businessDate, true);

            if ($day?->status === AccountingDayStatus::Closed) {
                throw new AccountingDayClosed('The current accounting day has already been closed.');
            }

            if ($day?->status === AccountingDayStatus::Closing) {
                throw new AccountingDayClosed('The current accounting day is closing.');
            }

            if ($day?->status === AccountingDayStatus::Open) {
                return $day;
            }

            if ($day === null) {
                return $this->repository->create([
                    'tenant_id' => $tenantId,
                    'business_date' => $businessDate,
                    'timezone' => $this->clock->timezone($tenantId),
                    'status' => AccountingDayStatus::Open,
                    'opened_at' => $now->utc(),
                    'opened_by' => $openedBy,
                    'opening_source' => $source,
                ]);
            }

            return $this->repository->update($day, [
                'status' => AccountingDayStatus::Open,
                'opened_at' => $now->utc(),
                'opened_by' => $openedBy,
                'opening_source' => $source,
                'update_key' => $day->update_key + 1,
            ]);
        });
    }

    private function closePreviousDayAtMidnight(int $tenantId, CarbonImmutable $now): void
    {
        DB::transaction(function () use ($tenantId, $now): void {
            $this->repository->lockTenant($tenantId);
            $latest = $this->repository->latestForTenant($tenantId, true);

            if ($latest === null || $latest->status === AccountingDayStatus::Closed || $latest->business_date->toDateString() >= $now->toDateString()) {
                return;
            }

            $effective = CarbonImmutable::parse($latest->business_date->toDateString(), $latest->timezone)->addDay()->startOfDay()->utc();
            $this->closeLockedDay($latest, AccountingDayClosingSource::PlatformMidnight, $effective, null, true);
        });
    }

    private function closeCurrentForAutomation(int $tenantId, CarbonImmutable $now, string $closeTime): void
    {
        DB::transaction(function () use ($tenantId, $now, $closeTime): void {
            $this->repository->lockTenant($tenantId);
            $day = $this->repository->findForTenantDate($tenantId, $now->toDateString(), true);

            if ($day === null || $day->status === AccountingDayStatus::Closed) {
                return;
            }

            $effective = CarbonImmutable::parse($now->toDateString().' '.$closeTime, $day->timezone)->utc();
            $this->closeLockedDay($day, AccountingDayClosingSource::Scheduled, $effective, null, true);
        });
    }

    private function closeLockedDay(TenantAccountingDay $day, AccountingDayClosingSource $source, CarbonImmutable $effectiveAt, ?int $closedBy, bool $force): TenantAccountingDay
    {
        if ($day->status === AccountingDayStatus::Closed) {
            return $day;
        }

        $blockers = $this->closingBlockers($day);

        if (! $force && $blockers !== []) {
            throw new InvalidTenantRequest('Accounting day cannot close while financial operations are unresolved.');
        }

        $day = $this->repository->update($day, [
            'status' => AccountingDayStatus::Closing,
            'closing_started_at' => now(),
            'update_key' => $day->update_key + 1,
        ]);
        $this->repository->replaceSummaries(
            $day,
            $this->repository->summaryData($day->tenant_id, $day->business_date->toDateString()),
        );

        return $this->repository->update($day, [
            'status' => AccountingDayStatus::Closed,
            'closed_at' => now(),
            'effective_closed_at' => $effectiveAt,
            'closed_by' => $closedBy,
            'closing_source' => $source,
            'close_metadata' => ['forced' => $force, 'blockers' => $blockers],
            'update_key' => $day->update_key + 1,
        ]);
    }

    private function closingBlockers(TenantAccountingDay $day): array
    {
        return [];
    }

    private function authorizeScheduleManagement(int $tenantId): void
    {
        $this->permissionService->authorizeAccountingDayOpen();
        $this->permissionService->authorizeAccountingDayClose();
        $this->licenseService->ensureTenantHasFeature($tenantId, 'automatic_open_close');
    }

    private function scheduleResource(int $tenantId): AccountingDayScheduleResource
    {
        return new AccountingDayScheduleResource(
            timezone: $this->clock->timezone($tenantId),
            days: $this->repository->schedules($tenantId)->map(fn ($day): array => [
                'weekday' => $day->weekday,
                'is_enabled' => $day->is_enabled,
                'open_time' => $day->open_time,
                'close_time' => $day->close_time,
                'update_key' => $day->update_key,
            ])->all(),
        );
    }
}
