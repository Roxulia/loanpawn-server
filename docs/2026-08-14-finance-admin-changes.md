# LonePawn Finance and Admin Changes — 2026-08-14 to 2026-08-15

## 1. Document purpose

This document describes the server and web-frontend changes completed on 2026-08-14 and 2026-08-15. It covers:

- Tenant default/reporting currency symbols.
- Reporting-currency changes and three-month recalculation.
- Behavior of financial operations while recalculation is running.
- Historical monthly financial summaries and current-month base-table reads.
- Financial movement API behavior.
- Accounting ledger currency and three-month history restrictions.
- Default-currency financial account enforcement.
- Tenant-defined historical exchange rates.
- Platform-admin tenant provisioning and free-license audit history.
- Platform-admin currency and exchange UI corrections.
- Financial-account assignment and authorization rules.
- Financial units, amount normalization, unit-aware inputs, and scaled reporting displays.

The desktop application is intentionally outside this change.

## 2. Architecture overview

The changes follow the existing Laravel layering:

```text
HTTP/command/job
    -> controller or queue handler
        -> domain service
            -> repository
                -> database
```

Tenant-owned reads and writes carry a tenant ID or execute under the existing tenant context. Long-running recalculation and summary work is delegated to queued jobs. Multi-record changes use database transactions and row locks where state consistency matters.

## 3. Currency metadata in tenant responses

### 3.1 Added response fields

`TenantSettingDetail` now exposes:

```json
{
  "default_currency_id": 1,
  "reporting_currency_id": 2,
  "effective_reporting_currency_id": 1,
  "default_currency_symbol": "K",
  "reporting_currency_symbol": "$",
  "effective_reporting_currency_symbol": "K",
  "reporting_currency_recalculation": null
}
```

The meanings are:

- `default_currency_id`: currency used for ordinary operational inputs and eligible operational financial accounts.
- `reporting_currency_id`: currency requested in tenant settings.
- `effective_reporting_currency_id`: currency currently used for calculated reporting amounts. During recalculation this remains the previous currency.
- `default_currency_symbol`: symbol belonging to `default_currency_id`.
- `reporting_currency_symbol`: symbol belonging to the requested reporting currency.
- `effective_reporting_currency_symbol`: symbol used for current reports and ledgers.
- `reporting_currency_recalculation`: active state and missing-rate requirements, or `null` when no recalculation is active.

### 3.2 Repository mapping flow

`TenantDetailRepository` joins currency-preference settings to the default and reporting currency rows. It also left-joins any active reporting-currency recalculation and the previous/effective currency.

The mapping process is:

1. Fetch all tenant detail rows, including every tenant setting.
2. Identify the `currency_preferences` row.
3. Copy the stored default and requested reporting currency IDs into `TenantSettingDetail`.
4. If a recalculation is active, use its previous currency as the effective reporting currency.
5. Populate all three symbols from their joined currency rows.
6. Include recalculation ID, status, date window, and missing exact-date rates.

### 3.3 Frontend consumption

The frontend `useTenantCurrencies()` hook centralizes the tenant currency values from the resolved tenant session:

- `defaultCurrencyId`
- `defaultCurrencySymbol`
- `reportingCurrencyId`
- `reportingCurrencySymbol`
- `effectiveReportingCurrencyId`
- `effectiveReportingCurrencySymbol`
- `reportingCurrencyRecalculation`

Financial displays no longer hard-code `MMK`. Dashboard totals and calculated reporting values use the effective reporting symbol. Operational transaction amounts use the default currency symbol.

The hook is used by the dashboard, accounting page, customer detail, settings, and financial-account selection components. `formatCurrencyAmount(value, symbol)` provides one formatting path for values with symbols.

## 4. Reporting currency change and recalculation

### 4.1 Persistence model

The `reporting_currency_recalculations` table stores one recalculation attempt with:

- Tenant ID.
- Previous reporting currency ID.
- Requested reporting currency ID.
- Three-month processing window.
- Status.
- Missing exact-date rates.
- Attempt count and failure message.
- Queue, start, and completion timestamps.

Active statuses are:

```text
queued
processing
waiting_for_rates
failed
```

`completed` is terminal and is not treated as active.

### 4.2 `TenantSettingService::updateCurrentTenantCurrencyPreferences()`

This function handles the settings request:

1. Resolve the current tenant.
2. Load or create its `currency_preferences` setting.
3. Verify the optimistic `update_key`.
4. Verify that default and reporting currencies are active and visible to the tenant.
5. In a transaction, update the requested currency IDs.
6. Call `ReportingCurrencyRecalculationService::start()` when the reporting ID changed.
7. Return the setting with requested and effective currency data.

Changing only the default currency does not enqueue a reporting recalculation.

### 4.3 `ReportingCurrencyRecalculationService::start()`

`start()` performs the state transition from a settings update to background work:

1. Return immediately if old and new reporting IDs are identical.
2. Lock/check for an active recalculation for the tenant.
3. Reject a second reporting-currency change while another recalculation is active.
4. Set `window_end` to the current business date.
5. Set `window_start` to the first day of the month two months before the current business month.
6. Create a `queued` recalculation record.
7. Dispatch `RecalculateReportingCurrencyJob` after the settings transaction commits.

This window covers the current business month plus the preceding two months.

### 4.4 Exchange-rate resolution

`ReportingExchangeRateService::conversion()` resolves conversion in this order:

1. If source and target currencies match, use rate/multiplier `1`.
2. Search visible direct pairs from source to target.
3. For each pair, resolve an entry for the exact business date.
4. Prefer a tenant-defined entry; fall back to a platform-defined entry for that exact date.
5. If no direct pair resolves, search the reverse pair.
6. For a direct pair, multiplier is the selling rate.
7. For a reverse pair, multiplier is `1 / selling_rate`.
8. Return `null` when no exact-date entry exists.

Recalculation intentionally does not use an earlier date's rate. Missing historical days must be supplied explicitly so historical reporting is reproducible.

### 4.5 `RecalculateReportingCurrencyJob`

The queued job:

- Uses the configured Redis/fallback queue connection.
- Runs on the `scheduled` queue.
- Uses `WithoutOverlapping` for the recalculation record.
- Calls `ReportingCurrencyRecalculationService::process()`.
- Marks the record as failed if the job terminates with an exception.

### 4.6 `ReportingCurrencyRecalculationService::process()`

The processing flow is:

1. Ignore missing or terminal recalculation records.
2. Mark the record `processing`, record `started_at`, and increment attempts.
3. Preflight all affected transactions for missing exact-date conversion rates.
4. If rates are missing, store a unique list of date/source/target combinations and move to `waiting_for_rates`.
5. Otherwise begin a transaction.
6. Lock the tenant currency-preference row.
7. Lock/reload the recalculation record.
8. Lock affected accounting transactions.
9. Resolve conversions again inside the lock.
10. If a rate disappeared or became unavailable, return to `waiting_for_rates` without partially converting transactions.
11. Update each transaction's `reporting_amount`, `exchange_rate`, and `update_key`.
12. Rebuild completed-month summaries inside the three-month window.
13. Mark the recalculation `completed` and set `completed_at`.
14. Bump affected tenant accounting cache namespaces.

Only transactions whose `currency_id` differs from the new reporting currency are selected. Date selection uses `business_date`, falling back to `occurred_at` when `business_date` is null.

### 4.7 Missing-rate recovery

Tenant exchange-rate input accepts an optional `effective_date` which must not be in the future. The entry writer stores that date independently of the observation timestamp.

After a tenant creates an exchange rate, `TenantExchangeRateService::create()` calls `retryPendingForTenant()`:

1. Find the tenant's active recalculation.
2. If it is `waiting_for_rates` or `failed`, move it to `queued`.
3. Dispatch the recalculation job after commit.

The settings UI shows the missing dates so the tenant can add the required historical rates.

## 5. Operations during recalculation

The system uses an effective-currency barrier so normal operations can continue safely.

### 5.1 Currency seen by readers

`effectiveCurrencyId()` returns:

- The previous reporting currency while any recalculation is active.
- The requested reporting currency after recalculation completes.

Dashboard totals, accounting totals, movement reports, ledger headings, and frontend warnings use this effective currency rather than switching labels before the data is converted.

### 5.2 Currency used by new accounting writes

`TenantAccountingTransactionService::recordTransaction()` calls `reportingValues()` inside its transaction.

`reportingValues()`:

1. Locks/reads the effective reporting currency.
2. Returns the transaction amount and rate `1` when source currency equals effective currency.
3. Preserves explicitly supplied reporting amount/rate when present.
4. Calculates from an explicitly supplied exchange rate when only the rate is provided.
5. Otherwise resolves an exact-date tenant/platform exchange rate.
6. Returns null reporting values if no valid conversion exists.

While recalculation is active, new transactions are therefore written in the previous effective reporting currency.

### 5.3 Serialization with the worker

Both transaction creation and the recalculation worker lock the currency-preference row before deciding which reporting currency applies.

```text
Operation reaches lock first
    |
    +-- Normal transaction first
    |      -> writes using previous effective currency
    |      -> worker later includes it if it is inside the recalculation window
    |
    +-- Recalculation worker first
           -> converts and completes under lock
           -> waiting transaction resumes
           -> sees requested currency as effective
           -> writes directly in the new currency
```

This prevents a transaction from being labeled with the new currency while holding an amount calculated for the old currency. Other tenant operations are not globally disabled.

## 6. Monthly financial summaries

### 6.1 Tables

`tenant_accounting_monthly_summaries` stores one row per tenant, completed month, source currency, and reporting currency. It includes incoming, outgoing, internal, net, reporting totals, and transaction count.

`financial_account_transaction_monthly_summaries` stores one row per tenant, completed month, and financial account. It includes debit, credit, net movement, account currency, and transaction count.

Both tables are tenant scoped and include `calculated_at`.

### 6.2 Accounting summary generation

`TenantAccountingMonthlySummaryService::summarize()`:

1. Calculates month start/end.
2. Queries non-deleted `TenantAccountingTransactions` for the tenant.
3. Uses `business_date`, with `occurred_at` fallback.
4. Groups rows by `currency_id`.
5. Calculates incoming/outgoing/internal totals and reporting totals.
6. Deletes existing rows for that tenant/month.
7. Inserts the rebuilt rows in the same transaction.

This delete-and-replace design makes repeated execution idempotent.

### 6.3 Financial account summary generation

`FinancialAccountMonthlySummaryService::summarize()`:

1. Joins `FinancialAccountTransaction` to its tenant-owned financial account.
2. Filters by tenant and transaction `created_at` within the month.
3. Groups by financial account and account currency.
4. Calculates debit, credit, net, and count.
5. Atomically replaces the tenant/month rows.

### 6.4 Scheduled job

`SummarizeMonthlyFinancialMovementsJob` runs on the first day of each month at `00:30` and summarizes the previous completed month for every tenant.

The job runs on the `scheduled` queue and uses a global `WithoutOverlapping` lock with a two-hour expiration.

### 6.5 Manual backfill command

The command is:

```bash
php artisan finance:summarize-monthly
```

Options:

```bash
php artisan finance:summarize-monthly --tenant=123
php artisan finance:summarize-monthly --from=2026-01 --to=2026-07
php artisan finance:summarize-monthly --tenant=123 --from=2026-01 --to=2026-07
```

The command refuses current or future months. Running the same completed month again safely replaces its summaries.

## 7. Financial movement query API

### 7.1 Endpoint

```http
GET /api/tenant/accounting/movements?start_at=2026-01-01&end_at=2026-08-14
```

The endpoint requires `list_accounting` permission and validates that `end_at` is not before `start_at`.

### 7.2 Hybrid read strategy

`FinancialMovementService::between()` divides the requested range into monthly segments:

- A full completed month is read from the summary tables.
- The current month is always read from base transaction tables.
- A partial historical month is read from base tables because its monthly summary would include dates outside the request.

Each returned row includes a `source` value of `summary` or `base`, allowing callers to see which path supplied it.

Response shape:

```json
{
  "start_date": "2026-01-01",
  "end_date": "2026-08-14",
  "effective_reporting_currency_id": 1,
  "accounting": [],
  "financial_accounts": []
}
```

This keeps completed-month queries compact while ensuring current and partial periods remain live.

## 8. Default-currency account enforcement

`MultiAccountManagement::findActiveDefaultCurrencyAccount()` extends the existing active-account lookup:

1. Resolve the active account for the current tenant.
2. Load current tenant currency preferences.
3. Require the account currency to match `default_currency_id`.
4. Throw an invalid-request exception for a mismatch.

The check is now used for:

- Pawn loan creation.
- Capital creation and update.
- Debt creation and update.
- Expense creation and update.

The frontend `FinancialAccountSelect` applies the same default-currency filter when no explicit matching account is requested. Backend validation remains authoritative.

## 9. Ledger behavior

Accounting ledger generation now:

- Limits both a single requested range and accessible history to three months.
- Resolves the effective reporting currency symbol through tenant currency settings.
- Returns the symbol in `AccountingLedger`.
- Adds the symbol to debit, credit, and balance spreadsheet headings.
- Uses the previous effective symbol while reporting-currency recalculation is pending.

The frontend ledger start-date input also prevents selection earlier than three months ago.

## 10. Platform-admin tenant provisioning

### 10.1 Routes and UI

Authenticated platform admins have four tenant-management routes:

```text
GET  /admin/tenants
GET  /admin/tenants/create
POST /admin/tenants
POST /admin/tenants/{tenant}/plan
```

The create screen has separate desktop and mobile Blade components. An admin selects an active platform owner, tenant/category/plan data, a fixed 1/3/6/12-month term, contact data, and a mandatory reason.

### 10.2 `AdminTenantProvisioningService::formOptions()`

Returns active platform-user options and active categories with active plans for the form.

### 10.3 `AdminTenantProvisioningService::provision()`

Provisioning flow:

1. Verify the selected platform owner is active.
2. Verify the plan is active and belongs to the selected category.
3. Resolve the authenticated platform admin.
4. Start a database transaction.
5. Call the existing tenant management service with `createdByAdmin = true`.
6. Create the tenant, active license, tenant owner, default branding, contact, settings, and default financial accounts.
7. Calculate license expiry from the selected fixed term.
8. Record tenant and license status logs with admin ID and reason.
9. Create an approved, zero-cost tenant request marking the action as `tenant_creation`, `admin_direct`, and `free_grant`.
10. Return `AdminTenantProvisionResult` containing the new tenant and license identifiers.

All dependent records roll back if any provisioning step fails.

## 11. Platform-admin free plan grants

### 11.1 Required audit data

Every immediate or scheduled admin grant requires `admin_review_note` and creates an approved `TenantRequest` with:

- `total_cost = 0`.
- Acting admin and review timestamp.
- Required reason.
- `admin_direct` and `free_grant` flags.
- Previous/new plan IDs.
- Previous/new expiry timestamps.
- Effective mode.

### 11.2 `AdminTenantLicenseGrantService::grant()`

The flow is:

1. Begin a transaction and lock the tenant.
2. Resolve the active target plan.
3. Lock the tenant license.
4. Reject the operation when another scheduled transition exists.
5. Resolve the authenticated admin and capture previous plan/status/expiry.
6. Create the approved zero-cost tenant request.
7. Create a plan transition.
8. For a scheduled grant, leave the transition scheduled from the current expiry through the selected duration.
9. For an immediate grant, mark the transition activated, update the license and tenant category, and preserve current expiry.
10. Add a license status log even for active-to-active grants so the admin action and reason remain visible.

Tenant and license locks prevent concurrent admin grants from silently overwriting one another.

## 12. Platform finance administration UI

The currency, exchange-pair, and exchange-rate Blade pages were normalized to the existing admin button system:

- `.button.primary`: create/record/save.
- `.button.secondary`: edit/correct/cancel.
- `.button.danger`: delete/void.

Crowded inline forms were replaced with dialogs:

- Currency: edit dialog and delete confirmation.
- Exchange pair: activation edit dialog and delete confirmation.
- Exchange rate: correction dialog and void dialog with required reasons.

Each page has a desktop table and separate mobile card presentation. Mobile actions become full-width, dialogs fit the viewport, statuses use badges, and dialog buttons have explicit button types. The exchange-pair multiplication label was also corrected.

## 13. Main added classes and functions

| Component | Function | Responsibility |
|---|---|---|
| `ReportingCurrencyRecalculationService` | `start()` | Create the three-month recalculation state and dispatch work. |
|  | `effectiveCurrencyId()` | Keep readers/writers on the previous currency until completion. |
|  | `reportingValues()` | Calculate reporting amount/rate for new transactions. |
|  | `retryPendingForTenant()` | Resume work after a missing historical rate is added. |
|  | `process()` | Validate rates, lock rows, convert transactions, rebuild summaries, complete state. |
|  | `markFailed()` | Persist job failure information. |
| `ReportingExchangeRateService` | `conversion()` | Resolve exact-date direct/reverse tenant-or-platform conversions. |
| `TenantAccountingMonthlySummaryService` | `summarize()` / `summarizeAll()` | Rebuild accounting summaries by currency. |
| `FinancialAccountMonthlySummaryService` | `summarize()` / `summarizeAll()` | Rebuild account debit/credit summaries. |
| `FinancialMovementService` | `between()` | Combine summaries and live base data by monthly segment. |
| `SummarizeMonthlyFinancialMovementsJob` | `handle()` | Summarize the previous month for all tenants. |
| `SummarizeMonthlyFinancialMovements` | `handle()` | Perform manual tenant/month backfills. |
| `AdminTenantProvisioningService` | `formOptions()` | Supply active owner/category/plan form choices. |
|  | `provision()` | Atomically create an admin-provisioned tenant and audited free license. |
| `AdminTenantLicenseGrantService` | `grant()` | Apply and audit immediate/scheduled free plan grants. |
| `TenantRequestService` | `createAdminApprovedGrant()` | Create the canonical zero-cost approved audit request. |
| `TenantLicenseService` | `getTenantLicenseForUpdate()` | Lock and return a tenant license for serialized mutation. |
|  | `updateLicense()` | Update a license through its repository. |
|  | `createPlanTransition()` | Persist activated or scheduled plan-transition history. |
| `MultiAccountManagement` | `findActiveDefaultCurrencyAccount()` | Enforce default-currency operational accounts. |
| Frontend finance hook | `useTenantCurrencies()` | Expose requested/effective IDs, symbols, and recalculation state. |
| Frontend formatter | `formatCurrencyAmount()` | Format a value with the tenant-provided symbol. |

## 14. Deployment and operating notes

Recommended deployment order:

1. Deploy server and frontend code.
2. Run migrations:

   ```bash
   php artisan migrate
   ```

3. Ensure a queue worker consumes the `scheduled` queue.
4. Ensure Laravel scheduler execution remains configured.
5. Backfill completed historical months if historical movement queries are needed immediately:

   ```bash
   php artisan finance:summarize-monthly --from=YYYY-MM --to=YYYY-MM
   ```

6. Confirm tenant currency preferences exist through the existing currency-settings maintenance command where required.

Operational checks:

- Monitor recalculations in `waiting_for_rates` and supply every listed exact date.
- Monitor `failed` recalculations and their `error_message`; adding/correcting a tenant rate requeues failed active work.
- Do not change reporting currency again until the active recalculation completes.
- Re-run monthly summary backfill after correcting historical base transactions.
- Treat summary tables as derived data; base transaction tables remain authoritative.

## 15. Verification completed on implementation

- PHP syntax checks passed for the added and modified admin backend classes.
- Admin tenant routes were registered successfully.
- Blade templates compiled successfully.
- Git whitespace validation passed.
- Server and frontend production builds passed during the implementation work.
- Development servers were not started.
- Automated tests were not run because repository instructions prohibit running tests unless explicitly requested.

## 16. Financial-account assignments — 2026-08-15

### 16.1 Assignment model and ownership rules

`FinancialAccountAssignment` is the access-control source for tenant financial accounts. Every newly created financial account automatically assigns the tenant owner. The `finance:backfill-owner-account-assignments` command supplies the same owner assignment for existing tenant accounts.

Assignment changes enforce these invariants:

- An authenticated user cannot assign or unassign their own financial accounts.
- Owner assignments cannot be removed or changed manually.
- Only active users in the current tenant can receive assignments.
- Every selected account must be active, available, and owned by the current tenant.
- Assignment replacement is transactional so validation failures cannot leave a partial assignment set.

### 16.2 Authorization behavior

Financial operations resolve accounts through assignment-aware service/repository queries. Non-owner users can list and use only their assigned accounts. Owners retain access to every tenant financial account through their automatic assignments.

Assignment management requires the dedicated tenant permission and continues to use the existing tenant-resolution, authentication, feature, and permission middleware. Cross-tenant IDs are never trusted from request payloads.

### 16.3 Assignment API and frontend

Staff detail responses include assigned financial-account summaries. Staff update flows accept the validated replacement account-ID list through the assignment service.

The web frontend provides:

- Account assignment controls on the staff edit page.
- Assigned-account summaries on the staff detail page.
- Assigned-user summaries on the financial-account detail page.
- Assignment-aware financial-account dropdowns for operational forms.
- English and Myanmar messages for self-assignment, owner protection, invalid accounts, inactive users, and authorization failures.

## 17. Financial units and amount normalization — 2026-08-15

### 17.1 Supported units

`FinancialUnit` defines stable unit codes and integer multipliers:

| Code | English label | Multiplier |
|---|---|---:|
| `UNIT` | Unit | 1 |
| `THOUSAND` | Thousand | 1,000 |
| `LAKH` | Lakh | 100,000 |
| `MILLION` | Million | 1,000,000 |
| `CRORE` | Crore | 10,000,000 |
| `BILLION` | Billion | 1,000,000,000 |

Each unit also exposes a Myanmar label. Unit selection is an input/display concern: persisted accounting, account balances, and report calculations remain base numeric amounts.

### 17.2 Unit reference endpoint

Authenticated tenant users can load the unit catalog from:

```http
GET /api/tenant/financial-units
```

The standard response envelope contains an ordered list with:

```json
{
  "code": "LAKH",
  "label_en": "Lakh",
  "label_mm": "သိန်း",
  "multiplier": 100000
}
```

The endpoint exposes static global reference data, so it requires tenant authentication but no feature-specific permission.

### 17.3 Backward-compatible write contract

Writable monetary fields accept an optional sibling unit field. Examples include:

- `amount` with `amount_unit`.
- `loan_amount` with `loan_amount_unit`.
- `payment_amount` with `payment_amount_unit`.
- `amount_paid` with `amount_paid_unit`.
- `balance` with `balance_unit`.
- `from_amount`/`fee_amount` with `from_amount_unit`/`fee_amount_unit`.
- Collateral `estimated_value`, `material_price_per_kyat`, and `minimum_retail_price` with their corresponding unit fields.

Omitting the unit preserves the previous API behavior and means `UNIT`. Controllers validate the enum and field relationship, then call `FinancialUnitService::toBase()` before constructing the existing request DTO. The service rejects unsupported units, non-finite results, and converted values exceeding the destination amount limit using localized message codes.

Normalization is applied to loan creation, collateral values, capital create/update, debt create/update/payment, expense creation, interest payments, redemptions, financial-account opening balances, and account transfers/fees. Exchange rates, interest rates, destination transfer amounts, and server-calculated payment breakdowns remain unscaled.

### 17.4 Unit-aware frontend input

The frontend `FinancialAmountInput` molecule combines a numeric input and financial-unit dropdown with joined borders and no gap. It has separate desktop and mobile presentation components while keeping the controls adjacent at both breakpoints.

`useFinancialUnits()` loads and caches the server catalog. Forms send the entered number and selected unit rather than duplicating multiplier logic in individual pages. Client-side comparisons that require a base amount use the shared `financialAmountToBase()` helper.

The component is used by:

- Loan and collateral entry.
- Capital, debt, debt-payment, and expense entry.
- Interest-payment and redemption workflows.
- Financial-account opening balance.
- Transfer amount and transfer fee.

Unit loading errors are localized and disable unit-aware entry instead of silently submitting an ambiguous value.

### 17.5 Reporting display behavior

`formatCurrencyAmount()` now delegates to `formatFinancialAmount()`. Reporting values are automatically scaled to the largest applicable supported unit while the underlying amount remains unchanged.

Examples:

```text
2,500 MMK       -> 2.5 Thousand MMK
2,500,000 MMK   -> 2.5 Million MMK
25,000,000 MMK  -> 2.5 Crore MMK
```

Dashboard and accounting report displays use this formatter. Ledger calculations and downloadable spreadsheet values remain unscaled base numbers for accounting accuracy.

### 17.6 Verification

- The frontend TypeScript production build completed successfully.
- PHP syntax checks passed for all added and modified financial-unit server files.
- The authenticated financial-unit route was registered successfully.
- Git whitespace validation passed.
- A unit test was added for catalog ordering, multipliers, normalization, and `UNIT` fallback.
- The server test suite was not executed, following repository instructions.
- The frontend lint command still reports pre-existing project-wide lint failures; the production build is clean.
- No development server was started.
