<?php

// Keep the shared guarded bootstrap and JSON worker lifecycle; this file never runs during syntax checks.
require dirname(__DIR__, 2).'/vendor/autoload.php';

exit((new Tests\Support\OwnershipFifoTask())->run($argv[1]));
