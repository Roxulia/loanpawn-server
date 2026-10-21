<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/** Shared child-process lifecycle; module tasks keep their own locking and business actions. */
abstract class WorkerTask
{
    public function run(string $payload): int
    {
        // Bootstrap, validate and report the server connection before executing a real action.
        try {
            $request = WorkerSetup::boot($payload);
            $this->validate($request);
            self::report(['state' => 'attempting', 'connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]);
            $count = $this->execute($request);
            self::report(['state' => 'success', 'count' => $count]);

            return 0;
        } catch (Throwable $exception) {
            // Return class-only diagnostics after unwinding any failed operation.
            if (DB::getFacadeApplication() !== null && DB::getFacadeApplication()->bound('db')) {
                TestDatabase::rollBack();
            }
            self::report(['state' => 'rejected', 'exception' => get_class($exception)]);

            return 2;
        }
    }

    public static function report(array $message): void
    {
        // Flush each JSON line immediately so the parent can coordinate contention.
        echo json_encode($message)."\n";
        fflush(STDOUT);
    }

    protected function validate(array $request): void
    {
        // Modules with additional fixture guards override this hook.
    }

    abstract protected function execute(array $request): ?int;
}
