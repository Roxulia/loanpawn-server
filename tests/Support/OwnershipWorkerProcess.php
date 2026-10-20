<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\{InputStream, Process};

/** Bounded coordination follows the existing Inventory tests' buffered-output/lock-wait pattern. */
trait OwnershipWorkerProcess
{
    protected function startOwnershipWorker(string $action, array $payload = []): array
    {
        // Pass only allowlisted nonsecret references, never test-environment contents or credentials.
        $allowed = ['items', 'owned_items', 'lots', 'purchase_orders', 'owned_item_code', 'lot_code',
            'quantity', 'source_code', 'wait', 'month', 'now'];
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new \LogicException('Unknown Ownership worker payload field.');
        }
        $payload = ['tenant' => (int) $this->ownershipFixture['tenant']->id, 'action' => $action] + $payload;
        $input = new InputStream();
        $process = new Process([PHP_BINARY, base_path('tests/Support/ownership_concurrency_worker.php'),
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], base_path(),
            ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        $process->setTimeout(20)->setInput($input)->start();
        $this->ownershipWorkers[] = $process;
        return [$process, $input];
    }

    protected function waitForOwnershipWorkerState(Process $process, string $state): array
    {
        // Buffered polling avoids the Windows pipe readiness race observed in the Inventory suite.
        $deadline = microtime(true) + 8;
        do {
            $process->checkTimeout();
            foreach (explode("\n", trim($process->getOutput())) as $line) {
                $message = json_decode($line, true);
                if (is_array($message) && ($message['state'] ?? null) === $state) return $message;
            }
            if (! $process->isRunning()) break;
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Missing Ownership worker state '.$state.'; last result: '.json_encode($this->ownershipWorkerLastMessage($process)));
    }

    protected function waitForOwnershipLockWait(Process $process): void
    {
        // Assert database-observed blocking rather than assuming a sleep proves concurrency.
        $attempt = $this->waitForOwnershipWorkerState($process, 'attempting');
        $deadline = microtime(true) + 5;
        do {
            $process->checkTimeout();
            $waiting = DB::selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits
                JOIN performance_schema.threads AS threads ON threads.THREAD_ID = waits.REQUESTING_THREAD_ID
                WHERE threads.PROCESSLIST_ID = ?', [$attempt['connection']]);
            if ((int) $waiting->total > 0) {
                $this->assertTrue(true);
                return;
            }
            if (! $process->isRunning()) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('No observed Ownership MySQL lock wait; last result: '.json_encode($this->ownershipWorkerLastMessage($process)));
    }

    protected function releaseOwnershipWorker(InputStream $input): void
    {
        // Release exactly one held transaction; committed fixture teardown still stops failed workers.
        $input->write("continue\n");
    }

    protected function ownershipWorkerLastMessage(Process $process): array
    {
        // Report only the controlled JSON protocol, not raw stderr or potentially sensitive SQL.
        $messages = array_filter(array_map(static fn (string $line): mixed => json_decode($line, true),
            explode("\n", trim($process->getOutput()))), 'is_array');
        return $messages === [] ? ['state' => 'no_protocol_output'] : end($messages);
    }
}
