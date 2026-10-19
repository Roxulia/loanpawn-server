<?php

namespace Tests\Feature\TenantModule;

use App\DataObjects\RequestObjects\InventoryLocationCreate;
use App\Services\TenantModule\InventoryService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\{InputStream, Process};
use Tests\Support\CommittedInventoryFixture;

class InventoryConcurrencyTest extends CommittedInventoryFixture
{
    #[DataProvider('trackingModes')]
    public function test_only_one_competing_operation_can_issue_the_final_quantity_or_identity(string $mode): void
    {
        $item = $this->fixtureItems[$mode];
        [$first, $input] = $this->worker('issue', [$item['inventory_item_code']], true, ['codes' => $item['serialized_item_codes']]);
        $this->awaitState($first, 'locked');
        [$second] = $this->worker('issue', [$item['inventory_item_code']], false, ['codes' => $item['serialized_item_codes']]);
        $this->awaitState($second, 'attempting');
        $this->awaitLockWait($second);
        $input->write("go\n");
        $first->wait();
        $second->wait();
        $this->assertSame('success', $this->lastMessage($first)['state'], $first->getErrorOutput());
        $this->assertSame('rejected', $this->lastMessage($second)['state'], $second->getOutput().$second->getErrorOutput());
        $this->assertSame(\App\Exceptions\InvalidTenantRequest::class, $this->lastMessage($second)['exception']);
        $this->assertSame('0.000', app(InventoryService::class)->itemDetail($item['inventory_item_code'])->totalQuantity);
        $this->assertSame(2, DB::table('inventory_movements')->where('tenant_id', $this->fixtureTenant->id)
            ->where('inventory_item_id', app(InventoryService::class)->findForOwnershipByCode($item['inventory_item_code'])->id)->count());
    }

    public static function trackingModes(): array
    {
        return [['QUANTITY'], ['SERIALIZED']];
    }

    public function test_reversed_item_input_orders_acquire_anchors_without_deadlock(): void
    {
        $codes = array_column($this->fixtureItems, 'inventory_item_code');
        [$first, $input] = $this->worker('lock', $codes, true);
        $this->awaitState($first, 'locked');
        [$second] = $this->worker('lock', array_reverse($codes), false);
        $this->awaitState($second, 'attempting');
        $this->awaitLockWait($second);
        $input->write("go\n");
        $first->wait(); $second->wait();
        $this->assertSame('success', $this->lastMessage($first)['state']);
        $this->assertSame('success', $this->lastMessage($second)['state']);
    }

    public function test_location_deactivation_waits_for_receipt_and_rejects_newly_held_stock(): void
    {
        $service = app(InventoryService::class);
        $location = $service->createLocation(new InventoryLocationCreate('Concurrent receipt destination', 'VAULT'));
        $item = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        [$first, $input] = $this->worker('receive', [$item], true, ['location' => $location['code'], 'hold_location' => true]);
        $this->awaitState($first, 'locked');
        [$second] = $this->worker('deactivate', [], false, ['location' => $location['code']]);
        $this->awaitState($second, 'attempting');
        $this->awaitLockWait($second);
        $input->write("go\n");
        $first->wait(); $second->wait();
        $this->assertSame('success', $this->lastMessage($first)['state']);
        $this->assertSame('rejected', $this->lastMessage($second)['state']);
        $this->assertSame(\App\Exceptions\InvalidTenantRequest::class, $this->lastMessage($second)['exception']);
        $this->assertSame(1, (int) DB::table('inventory_locations')->where('tenant_id', $this->fixtureTenant->id)->where('code', $location['code'])->value('is_active'));
    }

    public function test_summary_waits_for_append_transaction_and_preserves_the_live_tail(): void
    {
        $item = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        [$append, $input] = $this->worker('receive', [$item], true);
        $this->awaitState($append, 'locked');
        [$summary] = $this->worker('summary', [], false);
        $this->awaitState($summary, 'attempting');
        $this->awaitLockWait($summary);
        $input->write("go\n");
        $append->wait(); $summary->wait();
        $this->assertSame('success', $this->lastMessage($append)['state']);
        $this->assertSame('success', $this->lastMessage($summary)['state'], $summary->getOutput().$summary->getErrorOutput());
        $this->assertSame('2.000', app(InventoryService::class)->itemDetail($item)->totalQuantity);
        $this->assertSame(2, DB::table('inventory_monthly_checkpoints')->where('tenant_id', $this->fixtureTenant->id)->count());
    }

    public function test_competing_summaries_publish_one_checkpoint_per_item(): void
    {
        $code = $this->fixtureItems['QUANTITY']['inventory_item_code'];
        DB::beginTransaction();
        app(InventoryService::class)->prelockItems([$code]);
        [$first] = $this->worker('summary', [], false);
        [$second] = $this->worker('summary', [], false);
        $this->awaitState($first, 'attempting');
        $this->awaitState($second, 'attempting');
        $this->awaitLockWait($first);
        $this->awaitLockWait($second);
        DB::commit();
        $first->wait(); $second->wait();
        $this->assertSame('success', $this->lastMessage($first)['state'], $first->getOutput());
        $this->assertSame('success', $this->lastMessage($second)['state'], $second->getOutput());
        $this->assertSame(2, $this->lastMessage($first)['count'] + $this->lastMessage($second)['count']);
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
            [$append] = $this->worker('receive', [$code], false);
            $this->awaitState($append, 'attempting');
            $this->awaitLockWait($append);
        });
        $this->assertSame(2, app(\App\Services\TenantModule\InventoryMonthlySummaryService::class)->summarizeTenant($this->fixtureTenant->id, '2026-10'));
        $append->wait();
        $this->assertSame('success', $this->lastMessage($append)['state'], $append->getOutput().$append->getErrorOutput());
        $this->assertSame('2.000', app(InventoryService::class)->itemDetail($code)->totalQuantity);
    }

    private function worker(string $action, array $items, bool $wait, array $extra = []): array
    {
        // Only nonsecret fixture references travel in argv; stdin is reserved for releasing a holder.
        $input = new InputStream();
        $payload = base64_encode(json_encode(array_replace(['tenant' => $this->fixtureTenant->id, 'action' => $action,
            'items' => $items, 'location' => $this->fixtureLocation, 'wait' => $wait], $extra)));
        $process = new Process([PHP_BINARY, base_path('tests/Support/inventory_concurrency_worker.php'), $payload], base_path(),
            ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        $process->setTimeout(20)->setInput($input)->start();
        $this->workers[] = $process;
        return [$process, $input];
    }

    private function awaitState(Process $process, string $state): void
    {
        // Poll buffered output: Windows can consume a message before waitUntil's callback starts.
        $deadline = microtime(true) + 8;
        do {
            if (str_contains($process->getOutput(), '"state":"'.$state.'"')) {
                $this->assertTrue(true);
                return;
            }
            if (! $process->isRunning()) break;
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Missing worker state '.$state.': '.$process->getOutput().$process->getErrorOutput());
    }

    private function awaitLockWait(Process $process): void
    {
        $first = json_decode(explode("\n", trim($process->getOutput()))[0], true);
        $deadline = microtime(true) + 5;
        do {
            $waiting = DB::selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits JOIN performance_schema.threads AS threads ON threads.THREAD_ID = waits.REQUESTING_THREAD_ID WHERE threads.PROCESSLIST_ID = ?', [$first['connection']]);
            if ((int) $waiting->total > 0) { $this->assertTrue(true); return; }
            usleep(20000);
        } while ($process->isRunning() && microtime(true) < $deadline);
        $status = DB::selectOne('SELECT COMMAND, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID = ?', [$first['connection']]);
        $this->fail('Expected a real MySQL lock wait: '.json_encode($status).' '.$process->getOutput().$process->getErrorOutput());
    }

    private function lastMessage(Process $process): array
    {
        $lines = explode("\n", trim($process->getOutput()));
        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }
}
