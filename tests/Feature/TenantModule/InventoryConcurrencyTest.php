<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\InventoryLocationCreate;
use App\Services\TenantModule\InventoryService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedInventoryTestCase;

class InventoryConcurrencyTest extends CommittedInventoryTestCase
{
    #[DataProvider('trackingModes')]
    public function test_only_one_competing_operation_can_issue_the_final_quantity_or_identity(string $mode): void
    {
        $item = $this->fixtureItems[$mode];
        // Start the holder before its competing operation.
        [$first, $input] = $this->worker()->start('issue', ['items' => [$item['inventory_item_code']], 'wait' => true, 'codes' => $item['serialized_item_codes']]);
        $this->worker()->waitForState($first, 'locked');
        [$second] = $this->worker()->start('issue', ['items' => [$item['inventory_item_code']], 'wait' => false, 'codes' => $item['serialized_item_codes']]);
        $this->worker()->waitForState($second, 'attempting');
        $this->worker()->waitForLock($second);
        // Release only after observing the competing connection waiting on a lock.
        $this->worker()->release($input);
        $first->wait();
        $second->wait();
        $this->assertSame('success', $this->worker()->lastMessage($first)['state'], $first->getErrorOutput());
        $this->assertSame('rejected', $this->worker()->lastMessage($second)['state'], $second->getOutput().$second->getErrorOutput());
        $this->assertSame(\App\Exceptions\InvalidTenantRequest::class, $this->worker()->lastMessage($second)['exception']);
        $this->assertSame('0.000', app(InventoryService::class)->itemDetail($item['inventory_item_code'])->totalQuantity);
        $this->assertSame(2, DB::table('inventory_movements')->where('tenant_id', $this->fixtureTenant->id)
            ->where('inventory_item_id', app(InventoryService::class)->findForOwnershipByCode($item['inventory_item_code'])->id)->count());
    }

    public function test_reversed_item_input_orders_acquire_anchors_without_deadlock(): void
    {
        $codes = array_column($this->fixtureItems, 'inventory_item_code');
        // Start the holder before its competing operation.
        [$first, $input] = $this->worker()->start('lock', ['items' => $codes, 'wait' => true]);
        $this->worker()->waitForState($first, 'locked');
        [$second] = $this->worker()->start('lock', ['items' => array_reverse($codes), 'wait' => false]);
        $this->worker()->waitForState($second, 'attempting');
        $this->worker()->waitForLock($second);
        // Release only after observing the competing connection waiting on a lock.
        $this->worker()->release($input);
        $first->wait(); $second->wait();
        $this->assertSame('success', $this->worker()->lastMessage($first)['state']);
        $this->assertSame('success', $this->worker()->lastMessage($second)['state']);
    }

    public function test_location_deactivation_waits_for_receipt_and_rejects_newly_held_stock(): void
    {
        $service = app(InventoryService::class);
        $location = $service->createLocation(new InventoryLocationCreate('Concurrent receipt destination', 'VAULT'));
        $item = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        // Start the holder before its competing operation.
        [$first, $input] = $this->worker()->start('receive', ['items' => [$item], 'wait' => true, 'location' => $location['code'], 'hold_location' => true]);
        $this->worker()->waitForState($first, 'locked');
        [$second] = $this->worker()->start('deactivate', ['items' => [], 'wait' => false, 'location' => $location['code']]);
        $this->worker()->waitForState($second, 'attempting');
        $this->worker()->waitForLock($second);
        // Release only after observing the competing connection waiting on a lock.
        $this->worker()->release($input);
        $first->wait(); $second->wait();
        $this->assertSame('success', $this->worker()->lastMessage($first)['state']);
        $this->assertSame('rejected', $this->worker()->lastMessage($second)['state']);
        $this->assertSame(\App\Exceptions\InvalidTenantRequest::class, $this->worker()->lastMessage($second)['exception']);
        $this->assertSame(1, (int) DB::table('inventory_locations')->where('tenant_id', $this->fixtureTenant->id)->where('code', $location['code'])->value('is_active'));
    }

    public function test_summary_waits_for_append_transaction_and_preserves_the_live_tail(): void
    {
        $item = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        // Start the holder before its competing operation.
        [$append, $input] = $this->worker()->start('receive', ['items' => [$item], 'wait' => true]);
        $this->worker()->waitForState($append, 'locked');
        [$summary] = $this->worker()->start('summary', ['items' => [], 'wait' => false]);
        $this->worker()->waitForState($summary, 'attempting');
        $this->worker()->waitForLock($summary);
        // Release only after observing the competing connection waiting on a lock.
        $this->worker()->release($input);
        $append->wait(); $summary->wait();
        $this->assertSame('success', $this->worker()->lastMessage($append)['state']);
        $this->assertSame('success', $this->worker()->lastMessage($summary)['state'], $summary->getOutput().$summary->getErrorOutput());
        $this->assertSame('2.000', app(InventoryService::class)->itemDetail($item)->totalQuantity);
        $this->assertSame(2, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count());
    }

    public function test_competing_summaries_publish_one_checkpoint_per_item(): void
    {
        $code = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        DB::beginTransaction();
        app(InventoryService::class)->prelockItems([$code]);
        [$first] = $this->worker()->start('summary', ['items' => [], 'wait' => false]);
        [$second] = $this->worker()->start('summary', ['items' => [], 'wait' => false]);
        $this->worker()->waitForState($first, 'attempting');
        $this->worker()->waitForState($second, 'attempting');
        $this->worker()->waitForLock($first);
        $this->worker()->waitForLock($second);
        DB::commit();
        $first->wait(); $second->wait();
        $this->assertSame('success', $this->worker()->lastMessage($first)['state'], $first->getOutput());
        $this->assertSame('success', $this->worker()->lastMessage($second)['state'], $second->getOutput());
        $this->assertSame(2, $this->worker()->lastMessage($first)['count'] + $this->worker()->lastMessage($second)['count']);
        $this->assertSame(2, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count());
    }

    public function test_append_waits_for_checkpoint_publication_and_is_counted_in_live_tail(): void
    {
        $code = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        $append = null;
        $armed = true;
        // Hold the actual summarizer transaction at publication while its contender attempts append.
        DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($code, &$append, &$armed): void {
            if (! $armed || ! str_starts_with($query->sql, 'insert into `inventory_monthly_checkpoints`')) return;
            $armed = false;
            [$append] = $this->worker()->start('receive', ['items' => [$code], 'wait' => false]);
            $this->worker()->waitForState($append, 'attempting');
            $this->worker()->waitForLock($append);
        });
        $this->assertSame(2, app(\App\Services\TenantModule\InventoryMonthlySummaryService::class)->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $append->wait();
        $this->assertSame('success', $this->worker()->lastMessage($append)['state'], $append->getOutput().$append->getErrorOutput());
        $this->assertSame('2.000', app(InventoryService::class)->itemDetail($code)->totalQuantity);
    }

    public static function trackingModes(): array
    {
        return [['QUANTITY'], ['SERIALIZED']];
    }

}
