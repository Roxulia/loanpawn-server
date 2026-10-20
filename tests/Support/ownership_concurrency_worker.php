<?php

use App\Services\TenantModule\{InventoryService, OwnershipService};
use Illuminate\Support\Facades\DB;

try {
    // Reuse Inventory's guarded test connection; accept only this harness's isolated tenants.
    require __DIR__.'/inventory_worker_bootstrap.php';
    $tenantId = (int) $request['tenant'];
    $fixtureExists = DB::table('tenants')->where('id', $tenantId)->where('tenant_code', 'like', 'ot%')
        ->where('name', 'like', 'Ownership Test %')->exists();
    if (! $fixtureExists || ! in_array($request['action'], ['lock', 'reserve_sale', 'pledge', 'summary'], true)) {
        throw new LogicException('Unknown Ownership test fixture or worker action.');
    }
    // Report the real server connection ID so the parent can observe a genuine lock wait.
    $report(['state' => 'attempting', 'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
    $count = null;
    if ($request['action'] === 'summary') {
        // The future real summary service is intentionally not replaced with a fake implementation.
        $count = app(App\Services\TenantModule\OwnershipMonthlySummaryService::class)
            ->summarizeTenant($tenantId, $request['month'] ?? '2026-10');
    } else {
        // Discover immutable item references before beginning a repeatable-read transaction snapshot.
        $ownedCodes = array_values(array_unique(array_merge($request['owned_items'] ?? [],
            isset($request['owned_item_code']) ? [$request['owned_item_code']] : [])));
        $ownedItemCodes = DB::table('owned_items')->join('inventory_items', 'inventory_items.id', '=', 'owned_items.inventory_item_id')
            ->where('owned_items.tenant_id', $tenantId)->where('inventory_items.tenant_id', $tenantId)
            ->whereIn('owned_items.code', $ownedCodes)->pluck('inventory_items.code')->all();
        // Parent-source locks precede complete item anchors and Ownership aggregate/lot locks.
        DB::beginTransaction();
        $orders = array_values(array_unique($request['purchase_orders'] ?? []));
        $lockedOrders = DB::table('purchase_orders')->where('tenant_id', $tenantId)->whereIn('code', $orders)
            ->orderBy('id')->lockForUpdate()->get();
        if ($lockedOrders->count() !== count($orders)) throw new LogicException('Unknown fixture purchase order.');
        app(InventoryService::class)->prelockItems(array_merge($request['items'] ?? [], $ownedItemCodes));
        $ownedItems = DB::table('owned_items')->where('tenant_id', $tenantId)->whereIn('code', $ownedCodes)
            ->orderBy('id')->lockForUpdate()->get();
        if ($ownedItems->count() !== count($ownedCodes)) throw new LogicException('Unknown fixture Ownership aggregate.');
        $lotCodes = array_values(array_unique(array_merge($request['lots'] ?? [],
            isset($request['lot_code']) ? [$request['lot_code']] : [])));
        $lots = DB::table('acquisition_lots')->where('tenant_id', $tenantId)->whereIn('code', $lotCodes)
            ->whereIn('owned_item_id', $ownedItems->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        if ($lots->count() !== count($lotCodes)) throw new LogicException('Unknown fixture Ownership lot.');
        $report(['state' => 'locked']);

        // Holder release is bounded independently of the parent's process timeout.
        if (! empty($request['wait'])) {
            if (! stream_set_blocking(STDIN, false)) throw new RuntimeException('Nonblocking worker input is unavailable.');
            $deadline = microtime(true) + 10;
            $release = '';
            do {
                $chunk = fgets(STDIN);
                if ($chunk !== false) $release .= $chunk;
                if (str_contains($release, "\n")) break;
                if (feof(STDIN)) throw new RuntimeException('Worker input closed before release.');
                usleep(10000);
            } while (microtime(true) < $deadline);
            if (! str_contains($release, "\n") || trim($release) !== 'continue') throw new RuntimeException('Worker release timed out or was invalid.');
        }

        // These initial adapters call existing real primitives; case-specific new actions come with their tests.
        if ($request['action'] === 'reserve_sale') {
            app(OwnershipService::class)->reserveSale($request['owned_item_code'], $request['lot_code'],
                $request['quantity'], $request['source_code']);
        } elseif ($request['action'] === 'pledge') {
            app(OwnershipService::class)->reservePledge($request['owned_item_code'], $request['lot_code'],
                $request['quantity'], 'BusinessLoanCollateral', $request['source_code']);
        }
        DB::commit();
    }
    $report(['state' => 'success', 'count' => $count]);
} catch (Throwable $exception) {
    // Roll back failed workers and return class-only diagnostics, never SQL/bindings or credentials.
    if (class_exists(DB::class) && DB::getFacadeApplication() !== null && DB::getFacadeApplication()->bound('db')) {
        while (DB::connection()->transactionLevel() > 0) DB::rollBack();
    }
    echo json_encode(['state' => 'rejected', 'exception' => get_class($exception)])."\n";
    exit(2);
}
