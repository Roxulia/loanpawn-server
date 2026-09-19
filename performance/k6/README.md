# LonePawn k6 performance tests

These scripts use the same Sanctum CSRF and cookie authentication flow as the tenant SPA. Every script contains comments describing its configuration, requests, checks, and data handling.

## Prepare the testing database

Confirm `.env.testing` points to a dedicated persistent database, then run migrations yourself if needed. Populate or replace only the marked performance tenants with:

```powershell
php artisan config:clear --env=testing
php artisan db:seed --class=PerformanceTestingSeeder --env=testing
```

Each VU uses a different seeded user by default: VU 1 uses `owner001@performance.test`, VU 2 uses `user02001@performance.test`, and so on. The users remain in `perf-tenant-001` unless `K6_USERS_JSON` is provided. Keep `VUS` within the seeded `PERFORMANCE_SEED_USERS_PER_TENANT` count.

## Run the scripts

Start the application separately with the testing environment before running k6. The scripts default to `https://loanpawntest.1morebit.tech`; use `BASE_URL` and `ORIGIN` to target a local testing process.

```powershell
k6 run performance/k6/smoke.js
k6 run performance/k6/read-load.js
k6 run performance/k6/pawn-lifecycle.js
k6 run performance/k6/page-dashboard.js
k6 run performance/k6/page-loan-detail.js
k6 run performance/k6/page-debt-detail.js
k6 run performance/k6/page-business-loan-detail.js
```

Override configuration without editing JavaScript:

```powershell
$env:BASE_URL='https://loanpawntest.1morebit.tech'
$env:ORIGIN='https://app.loanpawntest.1morebit.tech'
$env:TENANT_CODE='perf-tenant-002'
# Legacy single-user override retained for reference; VU-aware credentials are used by default.
# $env:EMAIL='owner002@performance.test'
$env:PASSWORD='Performance123!'
$env:APP_VERSION='1.3.0'
$env:VUS='20'
$env:DURATION='5m'
k6 run performance/k6/read-load.js
```

For explicit users or multiple tenants, provide one credential object per VU:

```powershell
$env:K6_USERS_JSON='[{"tenantCode":"perf-tenant-001","email":"owner001@performance.test","password":"Performance123!"},{"tenantCode":"perf-tenant-001","email":"user02001@performance.test","password":"Performance123!"}]'
$env:VUS='2'
k6 run performance/k6/read-load.js
```

Page scenarios reproduce the API calls made while loading common frontend pages. They report both individual API latency and a custom `page_load_duration` metric. Use `REQUEST_MODE=sequential` to model a request waterfall or `REQUEST_MODE=parallel` to model independent requests started together:

```powershell
$env:REQUEST_MODE='sequential'
$env:VUS='5'
$env:DURATION='2m'
k6 run performance/k6/page-loan-detail.js

$env:REQUEST_MODE='parallel'
k6 run performance/k6/page-loan-detail.js
```

The loan-detail and debt-detail scenarios resolve records from the seeded performance data. You can provide `SLIP_CODE` or `DEBT_CODE` to test a specific record. The business-loan-detail scenario creates an isolated business loan per iteration unless `BUSINESS_LOAN_CODE` is provided, so run it only against a dedicated performance tenant.

`smoke.js` validates authentication and essential reads. `read-load.js` measures common browsing traffic. The `page-*.js` scenarios measure page-shaped API workflows and compare sequential versus parallel requests. `pawn-lifecycle.js` creates a new slip, pays its first interest row, and redeems it during every iteration, so reseed before repeating comparable write benchmarks.

Increase VUs and duration gradually while watching `http_req_failed`, p95 response time, application errors, database CPU, slow queries, connection usage, queue depth, and memory. The write scenario can reach package limits or grow financial history quickly when configured aggressively.
