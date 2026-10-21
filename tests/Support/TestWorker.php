<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Symfony\Component\Process\{InputStream, Process};

/** Process coordination shared by V2 concurrency tests. */
abstract class TestWorker
{
    private array $processes = [];

    public function __construct(protected int $tenantId, protected array $defaults = []) {}

    public function start(string $action, array $payload = []): array
    {
        // Send only module-approved references; stdin releases a held transaction.
        $payload = $this->payload($action, $payload);
        $input = new InputStream();
        $process = new Process([PHP_BINARY, base_path('tests/Support/'.$this->script()),
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))], base_path(),
            ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync']);
        $process->setTimeout(20)->setInput($input);
        // Register before starting so teardown can handle a failed launch.
        $this->processes[] = $process;
        $process->start();

        return [$process, $input];
    }

    public function waitForState(Process $process, string $state): array
    {
        // Read buffered JSON because Windows may deliver output before polling starts.
        $deadline = microtime(true) + 8;
        do {
            $process->checkTimeout();
            foreach ($this->messages($process) as $message) {
                if (($message['state'] ?? null) === $state) {
                    Assert::assertTrue(true);

                    return $message;
                }
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        Assert::fail('Missing worker state '.$state.'; last result: '.json_encode($this->lastMessage($process)));
    }

    public function waitForLock(Process $process): void
    {
        // Observe a real database lock wait rather than treating elapsed time as evidence.
        $attempt = $this->waitForState($process, 'attempting');
        $deadline = microtime(true) + 5;
        do {
            $process->checkTimeout();
            $waiting = DB::selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits AS waits
                JOIN performance_schema.threads AS threads ON threads.THREAD_ID = waits.REQUESTING_THREAD_ID
                WHERE threads.PROCESSLIST_ID = ?', [$attempt['connection']]);
            if ((int) $waiting->total > 0) {
                Assert::assertTrue(true);

                return;
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        Assert::fail('No observed MySQL lock wait; last result: '.json_encode($this->lastMessage($process)));
    }

    public function release(InputStream $input): void
    {
        // Keep each module's existing release message.
        $input->write($this->releaseMessage()."\n");
    }

    public function lastMessage(Process $process): array
    {
        // Expose controlled protocol messages instead of raw SQL or connection errors.
        $messages = $this->messages($process);

        return $messages === [] ? ['state' => 'no_protocol_output'] : end($messages);
    }

    public function stop(): void
    {
        // Stop every registered process before removing committed fixture rows.
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    protected function payload(string $action, array $payload): array
    {
        // Preserve explicit module defaults while preventing tenant/action overrides.
        return ['tenant' => $this->tenantId, 'action' => $action] + $payload + $this->defaults;
    }

    abstract protected function script(): string;

    abstract protected function releaseMessage(): string;

    private function messages(Process $process): array
    {
        // Ignore incomplete lines until the next buffered read.
        return array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true),
            explode("\n", trim($process->getOutput()))), 'is_array'));
    }
}
