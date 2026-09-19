<?php

return [
    // Keep local defaults safe while allowing larger dedicated test profiles.
    'tenant_count' => (int) env('PERFORMANCE_SEED_TENANTS', 3),
    'users_per_tenant' => (int) env('PERFORMANCE_SEED_USERS_PER_TENANT', 10),
    'customers_per_tenant' => (int) env('PERFORMANCE_SEED_CUSTOMERS_PER_TENANT', 10000),
    'slip_count' => (int) env('PERFORMANCE_SEED_SLIPS', 50000),
    'lenders_per_tenant' => (int) env('PERFORMANCE_SEED_LENDERS_PER_TENANT', 25),
    'business_loans_per_tenant' => (int) env('PERFORMANCE_SEED_BUSINESS_LOANS_PER_TENANT', 100),
    'business_loan_accruals_per_loan' => (int) env('PERFORMANCE_SEED_BUSINESS_LOAN_ACCRUALS_PER_LOAN', 2),
    'business_loan_payments_per_loan' => (int) env('PERFORMANCE_SEED_BUSINESS_LOAN_PAYMENTS_PER_LOAN', 1),
    'scheduled_expenses_per_tenant' => (int) env('PERFORMANCE_SEED_SCHEDULED_EXPENSES_PER_TENANT', 50),
    'debt_accruals_per_debt' => (int) env('PERFORMANCE_SEED_DEBT_ACCRUALS_PER_DEBT', 2),
    'debt_payments_per_debt' => (int) env('PERFORMANCE_SEED_DEBT_PAYMENTS_PER_DEBT', 1),
    'scheduled_occurrences_per_expense' => (int) env('PERFORMANCE_SEED_SCHEDULED_OCCURRENCES_PER_EXPENSE', 2),
    'chunk_size' => (int) env('PERFORMANCE_SEED_CHUNK_SIZE', 500),
    'random_seed' => (int) env('PERFORMANCE_SEED_RANDOM_SEED', 20260906),
    'password' => env('PERFORMANCE_SEED_PASSWORD', 'Performance123!'),
];
