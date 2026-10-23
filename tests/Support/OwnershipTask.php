<?php

namespace Tests\Support;

use App\Services\TenantModule\OwnershipService;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

class OwnershipTask extends WorkerTask
{
    protected function validate(array $request): void
    {
        // Accept only the isolated Ownership fixture and established action list.
        $tenantId = (int) $request['tenant'];
        $fixtureExists = DB::table('tenants')->where('id', $tenantId)->where('tenant_code', 'like', 'ot%')
            ->where('name', 'like', 'Ownership Test %')->exists();
        if (! $fixtureExists || ! in_array($request['action'], ['lock', 'reserve_sale', 'pledge', 'summary', 'acquire', 'link', 'reserve_batch'], true)) {
            throw new LogicException('Unknown Ownership test fixture or worker action.');
        }
    }

    protected function execute(array $request): ?int
    {
        // Run the real summary without inventing a replacement for pending services.
        if ($request['action'] === 'summary') {
            // Pause only after the real summary has acquired its own locks and inserted a header.
            // This lets another connection contend without wrapping publication in a fake transaction.
            $paused = false;
            DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($request, &$paused): void {
                if (! $paused && ! empty($request['wait']) && str_starts_with($query->sql, 'insert into `ownership_monthly_checkpoints`')) {
                    $paused = true;
                    self::report(['state' => 'locked']);
                    $this->waitForRelease($request);
                }
            });
            return app(\App\Services\TenantModule\OwnershipMonthlySummaryService::class)
                ->summarizeTenant((int) $request['tenant'], $request['month'] ?? '2026-10');
        }

        // Acquire locks before releasing the holder to execute its business action.
        $this->lockSources($request);
        self::report(['state' => 'locked']);
        $this->waitForRelease($request);
        $this->changeOwnership($request);
        DB::commit();

        return null;
    }

    private function lockSources(array $request): void
    {
        $tenantId = (int) $request['tenant'];
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
        // Exercise the production complete-set helper rather than proving only test-written anchor order.
        app(OwnershipService::class)->prelockItems(array_merge($request['items'] ?? [], $ownedItemCodes));
        $ownedItems = DB::table('owned_items')->where('tenant_id', $tenantId)->whereIn('code', $ownedCodes)
            ->orderBy('id')->lockForUpdate()->get();
        if ($ownedItems->count() !== count($ownedCodes)) throw new LogicException('Unknown fixture Ownership aggregate.');
        $lotCodes = array_values(array_unique(array_merge($request['lots'] ?? [],
            isset($request['lot_code']) ? [$request['lot_code']] : [])));
        $lots = DB::table('acquisition_lots')->where('tenant_id', $tenantId)->whereIn('code', $lotCodes)
            ->whereIn('owned_item_id', $ownedItems->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        if ($lots->count() !== count($lotCodes)) throw new LogicException('Unknown fixture Ownership lot.');
    }

    protected function waitForRelease(array $request): void
    {
        // Holder release is bounded independently of the parent's process timeout.
        if (! empty($request['wait'])) {
            // Windows cannot make this stdin pipe nonblocking. The parent's twenty-second process
            // timeout and teardown still bound its blocking read and stop it on any contender failure.
            if (! stream_set_blocking(STDIN, false)) {
                $release = fgets(STDIN);
                if ($release === false || trim($release) !== 'continue') throw new RuntimeException('Worker release was invalid.');
                return;
            }
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
    }

    private function changeOwnership(array $request): void
    {
        // These initial adapters call existing real primitives; case-specific new actions come with their tests.
        if ($request['action'] === 'reserve_sale') {
            app(OwnershipService::class)->reserveSale($request['owned_item_code'], $request['lot_code'],
                $request['quantity'], $request['source_code'], serializedItemCodes: $request['codes'] ?? []);
        } elseif ($request['action'] === 'pledge') {
            app(OwnershipService::class)->reservePledge($request['owned_item_code'], $request['lot_code'],
                $request['quantity'], 'BusinessLoanCollateral', $request['source_code'],
                serializedItemCodes: $request['codes'] ?? [], sourceContext: $this->pledgeSource($request));
        } elseif ($request['action'] === 'acquire') {
            app(OwnershipService::class)->acquirePurchaseLine($request['purchase_line'], $request['key']);
        } elseif ($request['action'] === 'link') {
            app(OwnershipService::class)->linkReceipt($request['owned_item_code'], $request['lot_code'],
                \App\DTOs\Ownership\Public\RequestObjects\OwnershipReceiptLinkRequest::fromValidated([
                    'purchase_receipt_line_code' => $request['receipt_line'], 'quantity' => $request['quantity'],
                    'serialized_item_codes' => $request['codes'] ?? [], 'update_key' => $request['update_key'],
                ], $request['key']));
        } elseif ($request['action'] === 'reserve_batch') {
            // All anchors were locked in canonical order even when caller input is reversed.
            foreach ($request['sources'] as $source) {
                app(OwnershipService::class)->reserveSale($source['owned'], $source['lot'], '1.000', $source['sale']);
            }
        }
    }

    private function pledgeSource(array $request): \App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource
    {
        // Explicit resolver-output boundary data; do not invent lender tables or bypass authorization.
        $currency = DB::table('acquisition_lots')->where('tenant_id', $request['tenant'])
            ->where('code', $request['lot_code'])->value('currency_code');
        return new \App\DTOs\Ownership\Internal\RequestObjects\OwnershipPledgeSource(tenantId: (int) $request['tenant'],
            sourceType: 'BusinessLoanCollateral', sourceCode: $request['source_code'],
            ownedItemCode: $request['owned_item_code'], lotCode: $request['lot_code'],
            quantity: $request['quantity'], currencyCode: $currency, serializedItemCodes: $request['codes'] ?? []);
    }
}
