<?php

namespace App\Services\PlatformModule;

use App\DataObjects\RequestObjects\AccountingDayScheduleUpdate;
use App\DataObjects\ResponseObjects\AccountingDayScheduleResource;
use App\Repository\TenantAccountingDayRepository;
use App\Services\PlatformModule\TenantServices\TenantLicenseService;
use App\Services\TenantModule\AccountingDayBusinessClock;
use Illuminate\Support\Facades\DB;

class PlatformTenantAccountingDayScheduleService
{
    public function __construct(
        private TenantAccountingDayRepository $repository,
        private PlatformTenantPageService $tenantPageService,
        private TenantLicenseService $tenantLicenseService,
        private AccountingDayBusinessClock $clock,
    ) {}

    public function schedule(int $tenantId): AccountingDayScheduleResource
    {
        $this->authorize($tenantId);
        $storedDays = $this->repository->schedules($tenantId)->keyBy('weekday');
        $days = collect(range(0, 6))->map(function (int $weekday) use ($storedDays): array {
            $day = $storedDays->get($weekday);

            return [
                'weekday' => $weekday,
                'is_enabled' => (bool) ($day?->is_enabled ?? false),
                'open_time' => substr((string) ($day?->open_time ?? '09:00'), 0, 5),
                'close_time' => substr((string) ($day?->close_time ?? '17:00'), 0, 5),
                'update_key' => (int) ($day?->update_key ?? 0),
            ];
        })->all();

        return new AccountingDayScheduleResource($this->clock->timezone($tenantId), $days);
    }

    public function update(int $tenantId, AccountingDayScheduleUpdate $request): AccountingDayScheduleResource
    {
        $this->authorize($tenantId);

        DB::transaction(function () use ($tenantId, $request): void {
            $this->repository->lockTenant($tenantId);

            foreach ($request->days as $day) {
                $this->repository->upsertSchedule($tenantId, $day);
            }
        });

        return $this->schedule($tenantId);
    }

    private function authorize(int $tenantId): void
    {
        $this->tenantPageService->findOwnedTenant($tenantId);
        $this->tenantLicenseService->ensureTenantHasFeature($tenantId, 'automatic_open_close');
    }
}
