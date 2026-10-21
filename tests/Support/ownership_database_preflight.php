<?php

// Read-only metadata checks use explicit classes, never variables leaked by another script.
require dirname(__DIR__, 2).'/vendor/autoload.php';

exit((new Tests\Support\OwnershipDatabaseCheck())->run());
