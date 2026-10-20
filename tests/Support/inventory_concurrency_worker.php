<?php

// Share the unchanged connection/cache/request bootstrap with Ownership test workers.
require __DIR__.'/inventory_worker_bootstrap.php';
try {
    $service = app(App\Services\TenantModule\InventoryService::class);
    $report(['state' => 'attempting', 'connection' => Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
    if (in_array($request['action'], ['issue', 'lock', 'receive'], true)) {
        Illuminate\Support\Facades\DB::beginTransaction();
        $service->prelockItems($request['items']);
        // Hold the destination's shared custody lock before testing a competing restriction edit.
        if (! empty($request['hold_location'])) {
            $service->lockStockForOperation($request['items'], [$request['location']]);
        }
        $report(['state' => 'locked']);
        if (! empty($request['wait'])) fgets(STDIN);
        if ($request['action'] === 'issue') {
            $service->issueStock(new App\DataObjects\RequestObjects\InventoryIssueRequest(
                inventoryItemCode: $request['items'][0], locationCode: $request['location'], quantity: '1',
                operationContext: App\Enums\InventoryIssueContext::Manual, serializedItemCodes: $request['codes'] ?? [],
            ));
        } elseif ($request['action'] === 'receive') {
            $service->receive(new App\DataObjects\RequestObjects\InventoryReceiveRequest(
                locationCode: $request['location'], quantity: '1', inventoryItemCode: $request['items'][0],
            ));
        }
        Illuminate\Support\Facades\DB::commit();
    } elseif ($request['action'] === 'summary') {
        $count = app(App\Services\TenantModule\InventoryMonthlySummaryService::class)->summarizeTenant($request['tenant'], '2026-10');
    } elseif ($request['action'] === 'deactivate') {
        $service->updateLocationByCode(new App\DataObjects\RequestObjects\InventoryLocationUpdate($request['location'], 0, isActive: false));
    } else throw new LogicException('Unknown verification action.');
    $report(['state' => 'success', 'count' => $count ?? null]);
} catch (Throwable $exception) {
    while (Illuminate\Support\Facades\DB::connection()->transactionLevel() > 0) Illuminate\Support\Facades\DB::rollBack();
    $report(['state' => 'rejected', 'exception' => get_class($exception)]);
    exit(2);
}
