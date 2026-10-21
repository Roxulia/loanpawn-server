<?php

namespace Tests\Support;

use App\Services\TenantModule\InventoryService;
use Illuminate\Support\Facades\DB;
use LogicException;

class InventoryTask extends WorkerTask
{
    protected function execute(array $request): ?int
    {
        // Dispatch module actions while preserving their original locking boundaries.
        $service = app(InventoryService::class);
        if (in_array($request['action'], ['issue', 'lock', 'receive'], true)) {
            $this->changeLockedStock($service, $request);
        } elseif ($request['action'] === 'summary') {
            return app(\App\Services\TenantModule\InventoryMonthlySummaryService::class)
                ->summarizeTenant($request['tenant'], '2026-10');
        } elseif ($request['action'] === 'deactivate') {
            $service->updateLocationByCode(new \App\DataObjects\RequestObjects\InventoryLocationUpdate(
                $request['location'], 0, isActive: false));
        } else {
            throw new LogicException('Unknown verification action.');
        }

        return null;
    }

    private function changeLockedStock(InventoryService $service, array $request): void
    {
        // Lock anchors before announcing that the holder is ready.
        DB::beginTransaction();
        $service->prelockItems($request['items']);
        // Hold the destination's shared custody lock before testing a competing restriction edit.
        if (! empty($request['hold_location'])) {
            $service->lockStockForOperation($request['items'], [$request['location']]);
        }
        self::report(['state' => 'locked']);
        $this->waitForRelease($request);
        $this->changeStock($service, $request);
        DB::commit();
    }

    private function waitForRelease(array $request): void
    {
        // Inventory accepts its original release line through stdin.
        if (! empty($request['wait'])) {
            fgets(STDIN);
        }
    }

    private function changeStock(InventoryService $service, array $request): void
    {
        // Exercise the same real stock operation as the original worker.
        if ($request['action'] === 'issue') {
            $service->issueStock(new \App\DataObjects\RequestObjects\InventoryIssueRequest(
                inventoryItemCode: $request['items'][0], locationCode: $request['location'], quantity: '1',
                operationContext: \App\Enums\InventoryIssueContext::Manual, serializedItemCodes: $request['codes'] ?? [],
            ));
        } elseif ($request['action'] === 'receive') {
            $service->receive(new \App\DataObjects\RequestObjects\InventoryReceiveRequest(
                locationCode: $request['location'], quantity: '1', inventoryItemCode: $request['items'][0],
            ));
        }
    }
}
