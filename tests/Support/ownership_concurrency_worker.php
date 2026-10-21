<?php

// Keep the worker CLI and JSON protocol stable while named helpers perform each step.
require dirname(__DIR__, 2).'/vendor/autoload.php';

exit((new Tests\Support\OwnershipTask())->run($argv[1]));
