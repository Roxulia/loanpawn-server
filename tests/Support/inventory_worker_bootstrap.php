<?php

// Compatibility entrypoint for existing worker includes; shared setup lives in a named helper.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$request = Tests\Support\WorkerSetup::boot($argv[1]);
$report = [Tests\Support\WorkerTask::class, 'report'];
