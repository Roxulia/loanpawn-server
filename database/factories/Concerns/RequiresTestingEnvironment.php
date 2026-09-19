<?php

namespace Database\Factories\Concerns;

use RuntimeException;

trait RequiresTestingEnvironment
{
    /** Prevent performance fixtures from touching a non-testing database. */
    protected function ensureTestingEnvironment(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Performance factories may only run when APP_ENV=testing.');
        }
    }
}
