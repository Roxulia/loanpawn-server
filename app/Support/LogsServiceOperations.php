<?php

namespace App\Support;

use Closure;

trait LogsServiceOperations
{
    protected function runLoggedOperation(string $operation, Closure $callback): mixed
    {
        return app(OperationLogger::class)->run($operation, $callback);
    }
}
