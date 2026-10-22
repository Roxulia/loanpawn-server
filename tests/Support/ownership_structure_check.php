<?php

// Inspect only the disposable MySQL schema selected by TestDatabase.
require dirname(__DIR__, 2).'/vendor/autoload.php';

exit((new Tests\Support\OwnershipStructureCheck())->run());
