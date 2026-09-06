# LonePawn k6 performance tests

These scripts use the same Sanctum CSRF and cookie authentication flow as the tenant SPA. Every script contains comments describing its configuration, requests, checks, and data handling.

## Prepare the testing database

Confirm `.env.testing` points to a dedicated persistent database, then run migrations yourself if needed. Populate or replace only the marked performance tenants with:

```powershell
php artisan config:clear --env=testing
php artisan db:seed --class=PerformanceTestingSeeder --env=testing
```

The default login is tenant `perf-tenant-001`, email `owner001@performance.test`, and the value of `PERFORMANCE_SEED_PASSWORD`.

## Run the scripts

Start the application separately with the testing environment before running k6. The scripts default to `https://loanpawntest.1morebit.tech`; use `BASE_URL` and `ORIGIN` to target a local testing process.

```powershell
k6 run performance/k6/smoke.js
k6 run performance/k6/read-load.js
k6 run performance/k6/pawn-lifecycle.js
```

Override configuration without editing JavaScript:

```powershell
$env:BASE_URL='https://loanpawntest.1morebit.tech'
$env:ORIGIN='https://app.loanpawntest.1morebit.tech'
$env:TENANT_CODE='perf-tenant-002'
$env:EMAIL='owner002@performance.test'
$env:PASSWORD='Performance123!'
$env:APP_VERSION='1.3.0'
$env:VUS='20'
$env:DURATION='5m'
k6 run performance/k6/read-load.js
```

`smoke.js` validates authentication and essential reads. `read-load.js` measures common browsing traffic. `pawn-lifecycle.js` creates a new slip, pays its first interest row, and redeems it during every iteration, so reseed before repeating comparable write benchmarks.

Increase VUs and duration gradually while watching `http_req_failed`, p95 response time, application errors, database CPU, slow queries, connection usage, queue depth, and memory. The write scenario can reach package limits or grow financial history quickly when configured aggressively.
