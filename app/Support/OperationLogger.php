<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class OperationLogger
{
    public function completed(string $operation): void
    {
        Log::channel('services')->info('Service operation completed.', [
            'operation' => $operation,
        ]);
    }

    public function failed(string $operation, Throwable $exception): void
    {
        Log::channel('services')->error('Service operation failed.', [
            'operation' => $operation,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    public function run(string $operation, Closure $callback): mixed
    {
        try {
            $result = $callback();

            $this->completed($operation);

            return $result;
        } catch (Throwable $exception) {
            $this->failed($operation, $exception);

            throw $exception;
        }
    }
}
