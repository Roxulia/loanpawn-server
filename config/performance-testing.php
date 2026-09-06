<?php

return [
    // Keep local defaults safe while allowing larger dedicated test profiles.
    'tenant_count' => (int) env('PERFORMANCE_SEED_TENANTS', 3),
    'users_per_tenant' => (int) env('PERFORMANCE_SEED_USERS_PER_TENANT', 10),
    'customers_per_tenant' => (int) env('PERFORMANCE_SEED_CUSTOMERS_PER_TENANT', 10000),
    'slip_count' => (int) env('PERFORMANCE_SEED_SLIPS', 50000),
    'chunk_size' => (int) env('PERFORMANCE_SEED_CHUNK_SIZE', 500),
    'random_seed' => (int) env('PERFORMANCE_SEED_RANDOM_SEED', 20260906),
    'password' => env('PERFORMANCE_SEED_PASSWORD', 'Performance123!'),
];
