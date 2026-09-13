<?php

namespace Database\Seeders;

use App\Models\CoreModule\Currency;
use App\Models\CoreModule\InterestType;
use App\Models\CoreModule\TenantBusinessLoan;
use App\Models\CoreModule\TenantBusinessLoanInterestAccrual;
use App\Models\CoreModule\TenantBusinessLoanPayment;
use App\Models\CoreModule\TenantBusinessLoanPaymentAllocation;
use App\Models\CoreModule\TenantDebtInterestAccrual;
use App\Models\CoreModule\TenantDebtPayment;
use App\Models\CoreModule\TenantDebtPaymentAllocation;
use App\Models\CoreModule\TenantExpense;
use App\Models\CoreModule\TenantLender;
use App\Models\CoreModule\TenantPerson;
use App\Models\CoreModule\TenantScheduledExpense;
use App\Models\CoreModule\TenantScheduledExpenseOccurrence;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountTypes;
use App\Models\PlatformModule\Package;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use Carbon\CarbonImmutable;
use Database\Factories\FinancialAccountFactory;
use Database\Factories\PawnCollateralItemFactory;
use Database\Factories\PawnInterestPaymentFactory;
use Database\Factories\PawnLoanContractSlipFactory;
use Database\Factories\PawnRedemptionFactory;
use Database\Factories\PlatformUserFactory;
use Database\Factories\TenantAccountingTransactionFactory;
use Database\Factories\TenantCustomerFactory;
use Database\Factories\TenantDebtFactory;
use Database\Factories\TenantFactory;
use Database\Factories\TenantLicenseFactory;
use Database\Factories\TenantUserFactory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PerformanceTestingSeeder extends Seeder
{
    use WithoutModelEvents;

    private const TENANT_PREFIX = 'perf-tenant-';

    /** Build a deterministic pawn workload only inside the dedicated testing environment. */
    public function run(): void
    {
        $this->ensureTestingEnvironment();
        $this->validateConfiguration();
        fake()->seed((int) config('performance-testing.random_seed'));

        $this->removeExistingPerformanceData();
        $this->call(DatabaseSeeder::class);

        $references = $this->referenceData();
        $tenantCount = (int) config('performance-testing.tenant_count');
        $totalSlips = (int) config('performance-testing.slip_count');

        for ($tenantNumber = 1; $tenantNumber <= $tenantCount; $tenantNumber++) {
            $slipCount = intdiv($totalSlips, $tenantCount) + ($tenantNumber <= $totalSlips % $tenantCount ? 1 : 0);
            $this->seedTenant($tenantNumber, $slipCount, $references);
        }

        $this->command?->info('Performance data created successfully.');
        $this->command?->table(
            ['Setting', 'Value'],
            [
                ['Tenant codes', self::TENANT_PREFIX.'001 ... '.self::TENANT_PREFIX.str_pad((string) $tenantCount, 3, '0', STR_PAD_LEFT)],
                ['Login email', 'owner001@performance.test'],
                ['Password', 'PERFORMANCE_SEED_PASSWORD from .env.testing'],
                ['Customers', number_format($tenantCount * (int) config('performance-testing.customers_per_tenant'))],
                ['Slips', number_format($totalSlips)],
            ],
        );
    }

    private function ensureTestingEnvironment(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('PerformanceTestingSeeder may only run when APP_ENV=testing.');
        }
    }

    private function validateConfiguration(): void
    {
        foreach (['tenant_count', 'users_per_tenant', 'customers_per_tenant', 'slip_count', 'chunk_size'] as $key) {
            if ((int) config("performance-testing.{$key}") < 1) {
                throw new RuntimeException("performance-testing.{$key} must be greater than zero.");
            }
        }

        foreach ([
            'lenders_per_tenant',
            'business_loans_per_tenant',
            'business_loan_accruals_per_loan',
            'business_loan_payments_per_loan',
            'scheduled_expenses_per_tenant',
            'debt_accruals_per_debt',
            'debt_payments_per_debt',
            'scheduled_occurrences_per_expense',
        ] as $key) {
            if ((int) config("performance-testing.{$key}") < 0) {
                throw new RuntimeException("performance-testing.{$key} may not be negative.");
            }
        }
    }

    /** Delete only the clearly marked performance tenants, leaving all other test fixtures intact. */
    private function removeExistingPerformanceData(): void
    {
        $tenants = DB::table('tenants')->where('tenant_code', 'like', self::TENANT_PREFIX.'%')->get(['id', 'platform_user_id']);

        if ($tenants->isEmpty()) {
            return;
        }

        $tenantIds = $tenants->pluck('id')->all();
        $ownerIds = $tenants->pluck('platform_user_id')->all();

        // Remove new dependent financial rows explicitly because SQLite test
        // connections enforce restrict-on-delete relationships differently
        // from the production database engine.
        foreach ([
            'tenant_business_loan_payment_allocations',
            'tenant_business_loan_payments',
            'tenant_business_loan_interest_accruals',
            'tenant_business_loans',
            'tenant_scheduled_expense_occurrences',
            'tenant_scheduled_expenses',
            'tenant_debt_payment_allocations',
            'tenant_debt_payments',
            'tenant_debt_interest_accruals',
            'pawn_redemptions',
            'pawn_interest_payments',
            'pawn_collateral_items',
            'tenant_debts',
            'pawn_loan_contract_slips',
            'tenant_lenders',
            'tenant_people',
            'tenant_expenses',
            'tenant_accounting_transactions',
            'financial_account_transfers',
        ] as $table) {
            DB::table($table)->whereIn('tenant_id', $tenantIds)->delete();
        }

        $accountIds = DB::table('financial_accounts')->whereIn('tenant_id', $tenantIds)->pluck('id');
        if ($accountIds->isNotEmpty()) {
            DB::table('financial_account_transfers')
                ->whereIn('from_account_id', $accountIds)
                ->orWhereIn('to_account_id', $accountIds)
                ->orWhereIn('fee_account_id', $accountIds)
                ->delete();
            DB::table('financial_accounts')->whereIn('id', $accountIds)->delete();
        }

        DB::table('tenants')->whereIn('id', $tenantIds)->delete();
        DB::table('platform_users')->whereIn('id', $ownerIds)->where('email', 'like', '%@performance.test')->delete();
    }

    private function referenceData(): array
    {
        $package = Package::query()->where('code', 'premium')->firstOrFail();
        $role = TenantRole::query()->where('name', 'Owner')->firstOrFail();
        $currency = Currency::query()->where('code', config('finance.default_currency', 'MMK'))->firstOrFail();
        $accountType = FinancialAccountTypes::query()->whereNull('tenant_id')->where('code', 'cash')->firstOrFail();
        $interestTypes = InterestType::query()->whereNull('tenant_id')->whereIn('code', ['daily', 'weekly', 'monthly'])->get()->keyBy('code');

        if ($interestTypes->count() !== 3) {
            throw new RuntimeException('Daily, weekly, and monthly interest types must be seeded first.');
        }

        $expenseTypes = \App\Models\CoreModule\ExpenseType::query()->whereNull('tenant_id')->get()->keyBy('code');

        return compact('package', 'role', 'currency', 'accountType', 'interestTypes', 'expenseTypes');
    }

    private function seedTenant(int $number, int $slipCount, array $references): void
    {
        $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        $tenantCode = self::TENANT_PREFIX.$suffix;
        $timezone = ['Asia/Yangon', 'Asia/Bangkok', 'UTC'][($number - 1) % 3];

        $owner = PlatformUser::query()->create(PlatformUserFactory::new()->raw([
            'code' => 'PERFPU'.$suffix,
            'name' => "Performance Owner {$suffix}",
            'email' => "owner{$suffix}@performance.test",
            'phone' => '09000'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
        ]));

        $tenant = Tenant::query()->create(TenantFactory::new()->raw([
            'platform_user_id' => $owner->id,
            'name' => "Performance Pawnshop {$suffix}",
            'tenant_code' => $tenantCode,
            'subdomain' => "perf-{$suffix}",
        ]));

        TenantLicense::query()->create(TenantLicenseFactory::new()->raw([
            'tenant_id' => $tenant->id,
            'plan_id' => $references['package']->id,
            'license_key' => "PERF-LICENSE-{$suffix}",
            'current_month_slip_count' => 0,
            'current_staff_count' => (int) config('performance-testing.users_per_tenant'),
            'current_account_count' => 3,
        ]));

        $users = $this->seedUsers($tenant, $suffix, $references['role']);
        $accounts = $this->seedAccounts($tenant, $suffix, $users[0], $references['accountType'], $references['currency']);
        $this->seedSettings($tenant, $timezone, $references['currency']);
        $customerIds = $this->seedCustomers($tenant, $suffix, $users, (int) config('performance-testing.customers_per_tenant'));
        $this->seedSlips($tenant, $suffix, $users, $accounts, $customerIds, $references['interestTypes'], $timezone, $slipCount);
        $lenders = $this->seedLenders($tenant, $suffix, $users);
        $this->seedBusinessLoans($tenant, $suffix, $users, $accounts, $lenders, $references['interestTypes'], $timezone);
        $this->seedScheduledExpenses($tenant, $suffix, $users, $accounts, $references['expenseTypes']);

        $this->command?->info("Seeded {$tenantCode}: {$slipCount} slips.");
    }

    /** Seed shared people and lender profiles used by business-loan workflows. */
    private function seedLenders(Tenant $tenant, string $suffix, array $users): array
    {
        $count = (int) config('performance-testing.lenders_per_tenant');
        if ($count === 0) {
            return [];
        }

        $now = now();
        $people = [];
        $emails = [];
        for ($index = 1; $index <= $count; $index++) {
            $code = str_pad((string) $index, 5, '0', STR_PAD_LEFT);
            $email = "lender{$suffix}{$code}@performance.test";
            $emails[] = $email;
            $people[] = array_merge(TenantPerson::factory()->raw([
                'name' => "Performance Lender {$suffix}-{$code}",
                'nrc' => "PERF/L/{$suffix}{$code}",
                'email' => $email,
                'phone' => '093'.substr($suffix.$code, -8),
            ]), [
                'tenant_id' => $tenant->id,
                'created_by' => $users[0]->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('tenant_people')->insert($people);

        $personRows = DB::table('tenant_people')
            ->where('tenant_id', $tenant->id)
            ->whereIn('email', $emails)
            ->orderBy('id')
            ->get();
        $lenders = [];
        foreach ($personRows as $position => $person) {
            $code = str_pad((string) ($position + 1), 5, '0', STR_PAD_LEFT);
            $lenders[] = array_merge(TenantLender::factory()->raw([
                'code' => "PERFL{$suffix}{$code}",
            ]), [
                'tenant_id' => $tenant->id,
                'person_id' => $person->id,
                'created_by' => $users[0]->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('tenant_lenders')->insert($lenders);

        return DB::table('tenant_lenders')->where('tenant_id', $tenant->id)->orderBy('id')->get()->all();
    }

    /** Seed business-loan principals and their interest/payment history. */
    private function seedBusinessLoans(Tenant $tenant, string $suffix, array $users, array $accounts, array $lenders, $interestTypes, string $timezone): void
    {
        $count = (int) config('performance-testing.business_loans_per_tenant');
        if ($count === 0) {
            return;
        }

        $now = CarbonImmutable::now($timezone);
        $loanRows = [];
        $codes = [];
        for ($index = 1; $index <= $count; $index++) {
            $sequence = str_pad((string) $index, 8, '0', STR_PAD_LEFT);
            $amount = 500_000 + (($index % 100) * 25_000);
            $code = "PERFBL-{$suffix}-{$sequence}";
            $codes[] = $code;
            $loanRows[] = array_merge(TenantBusinessLoan::factory()->raw([
                'code' => $code,
                'amount' => $amount,
                'principal_balance' => $amount - (($index % 5) * 10_000),
                'lender_id' => $lenders === [] ? null : $lenders[($index - 1) % count($lenders)]->id,
                'receipt_account_id' => $accounts[$index % count($accounts)]->id,
                'apply_interest' => true,
                'interest_rate' => 5,
                'interest_type_id' => $interestTypes['monthly']->id,
                'interest_anchor_at' => $now->subMonths(2)->utc(),
                'last_interest_paid_at' => $index % 4 === 0 ? $now->subMonth()->utc() : null,
            ]), [
                'tenant_id' => $tenant->id,
                'created_by' => $users[$index % count($users)]->id,
                'created_at' => $now->subDays($index % 180)->utc(),
                'updated_at' => $now->subDays($index % 30)->utc(),
            ]);
        }
        DB::table('tenant_business_loans')->insert($loanRows);

        $loans = DB::table('tenant_business_loans')->where('tenant_id', $tenant->id)->whereIn('code', $codes)->orderBy('id')->get();
        $accrualRows = [];
        foreach ($loans as $loan) {
            $accrualCount = (int) config('performance-testing.business_loan_accruals_per_loan');
            for ($index = 0; $index < $accrualCount; $index++) {
                $start = CarbonImmutable::parse($loan->interest_anchor_at, 'UTC')->subMonths($accrualCount - $index - 1)->startOfDay();
                $interest = round((float) $loan->principal_balance * 0.05, 2);
                $paid = $index === 0 && $loan->last_interest_paid_at !== null ? $interest : 0;
                $accrualRows[] = array_merge(TenantBusinessLoanInterestAccrual::factory()->raw([
                    'principal_amount' => $loan->principal_balance,
                    'calculated_interest' => $interest,
                    'paid_amount' => $paid,
                    'start_period_at' => $start->utc(),
                    'end_period_at' => $start->addMonth()->subSecond()->utc(),
                    'period_timezone' => $timezone,
                    'is_paid' => $paid >= $interest,
                ]), [
                    'tenant_id' => $tenant->id,
                    'business_loan_id' => $loan->id,
                    'created_at' => $loan->created_at,
                    'updated_at' => $loan->updated_at,
                ]);
            }
        }
        if ($accrualRows !== []) {
            DB::table('tenant_business_loan_interest_accruals')->insert($accrualRows);
        }

        $accruals = DB::table('tenant_business_loan_interest_accruals')->where('tenant_id', $tenant->id)->orderBy('id')->get()->groupBy('business_loan_id');
        $paymentRows = [];
        foreach ($loans as $loan) {
            $paymentCount = (int) config('performance-testing.business_loan_payments_per_loan');
            for ($index = 1; $index <= $paymentCount; $index++) {
                $paymentRows[] = array_merge(TenantBusinessLoanPayment::factory()->raw([
                    'code' => "PERFBLP-{$suffix}-{$loan->id}-{$index}",
                    'payment_amount' => 25_000,
                    'principal_paid' => 25_000,
                    'payment_at' => CarbonImmutable::parse($loan->created_at, 'UTC')->addDays(10 + $index),
                ]), [
                    'tenant_id' => $tenant->id,
                    'business_loan_id' => $loan->id,
                    'payment_account_id' => $accounts[0]->id,
                    'created_by' => $users[0]->id,
                    'created_at' => $loan->created_at,
                    'updated_at' => $loan->updated_at,
                ]);
            }
        }
        if ($paymentRows !== []) {
            DB::table('tenant_business_loan_payments')->insert($paymentRows);
        }

        $payments = DB::table('tenant_business_loan_payments')->where('tenant_id', $tenant->id)->orderBy('id')->get();
        $allocations = [];
        foreach ($payments as $payment) {
            $accrual = $accruals->get($payment->business_loan_id)?->first();
            if ($accrual === null) {
                continue;
            }
            $allocations[] = array_merge(TenantBusinessLoanPaymentAllocation::factory()->raw([
                'amount' => $payment->interest_paid,
            ]), [
                'tenant_id' => $tenant->id,
                'payment_id' => $payment->id,
                'accrual_id' => $accrual->id,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        }
        if ($allocations !== []) {
            DB::table('tenant_business_loan_payment_allocations')->insert($allocations);
        }
    }

    /** Seed active schedules plus pending and completed occurrence rows. */
    private function seedScheduledExpenses(Tenant $tenant, string $suffix, array $users, array $accounts, $expenseTypes): void
    {
        $count = (int) config('performance-testing.scheduled_expenses_per_tenant');
        if ($count === 0) {
            return;
        }

        $now = CarbonImmutable::now();
        $scheduleRows = [];
        $codes = [];
        $expenseTypeId = $expenseTypes->get('rent')?->id ?? $expenseTypes->first()?->id;
        for ($index = 1; $index <= $count; $index++) {
            $sequence = str_pad((string) $index, 6, '0', STR_PAD_LEFT);
            $start = $now->addDays(1 + ($index % 10))->startOfDay();
            $code = "PERFSE-{$suffix}-{$sequence}";
            $codes[] = $code;
            $scheduleRows[] = array_merge(TenantScheduledExpense::factory()->raw([
                'code' => $code,
                'account_id' => $accounts[$index % count($accounts)]->id,
                'expense_type_id' => $expenseTypeId,
                'start_date' => $start->toDateString(),
                'next_due_date' => $start->toDateString(),
                'monthly_anchor_day' => $start->day,
            ]), [
                'tenant_id' => $tenant->id,
                'created_by' => $users[$index % count($users)]->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        DB::table('tenant_scheduled_expenses')->insert($scheduleRows);

        $schedules = DB::table('tenant_scheduled_expenses')->where('tenant_id', $tenant->id)->whereIn('code', $codes)->orderBy('id')->get();
        $expenseRows = [];
        $occurrenceInputs = [];
        foreach ($schedules as $schedule) {
            $occurrenceCount = (int) config('performance-testing.scheduled_occurrences_per_expense');
            for ($index = 0; $index < $occurrenceCount; $index++) {
                $executed = $index > 0;
                $dueDate = CarbonImmutable::parse($schedule->start_date)->addMonths($index);
                $expenseCode = "PERFEX-{$suffix}-{$schedule->id}-{$index}";
                if ($executed) {
                    $expenseRows[] = array_merge(TenantExpense::factory()->raw([
                        'code' => $expenseCode,
                        'amount' => $schedule->amount,
                        'expense_type_id' => $schedule->expense_type_id,
                        'description' => $schedule->description,
                    ]), [
                        'tenant_id' => $tenant->id,
                        'created_by' => $schedule->created_by,
                        'created_at' => $dueDate->setTime(9, 0),
                        'updated_at' => $dueDate->setTime(9, 0),
                    ]);
                }
                $occurrenceInputs[] = compact('schedule', 'dueDate', 'executed', 'expenseCode');
            }
        }
        if ($expenseRows !== []) {
            DB::table('tenant_expenses')->insert($expenseRows);
        }

        $expenseIds = DB::table('tenant_expenses')->where('tenant_id', $tenant->id)->whereIn('code', array_column($expenseRows, 'code'))->pluck('id', 'code');
        $occurrences = [];
        foreach ($occurrenceInputs as $input) {
            $schedule = $input['schedule'];
            $dueDate = $input['dueDate'];
            $executed = $input['executed'];
            $occurrences[] = array_merge(TenantScheduledExpenseOccurrence::factory()->raw([
                'description' => $schedule->description,
                'amount' => $schedule->amount,
                'account_id' => $schedule->account_id,
                'expense_type_id' => $schedule->expense_type_id,
                'due_date' => $dueDate->toDateString(),
                'due_time' => $schedule->scheduled_time,
                'status' => $executed ? 'completed' : 'pending',
                'executed_at' => $executed ? $dueDate->setTime(9, 0) : null,
            ]), [
                'tenant_id' => $tenant->id,
                'scheduled_expense_id' => $schedule->id,
                'expense_id' => $executed ? $expenseIds->get($input['expenseCode']) : null,
                'created_at' => $dueDate->setTime(9, 0),
                'updated_at' => $dueDate->setTime(9, 0),
            ]);
        }
        if ($occurrences !== []) {
            DB::table('tenant_scheduled_expense_occurrences')->insert($occurrences);
        }
    }

    private function seedUsers(Tenant $tenant, string $suffix, TenantRole $role): array
    {
        $users = [];

        for ($index = 1; $index <= (int) config('performance-testing.users_per_tenant'); $index++) {
            $userSuffix = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $users[] = TenantUser::query()->create(TenantUserFactory::new()->raw([
                'tenant_id' => $tenant->id,
                'role_id' => $role->id,
                'code' => "PERFTU{$suffix}{$userSuffix}",
                'username' => "P{$suffix}{$userSuffix}",
                'name' => $index === 1 ? "Performance Owner {$suffix}" : "Performance Staff {$suffix}-{$userSuffix}",
                'nrc' => "PERF/NRC/{$suffix}{$userSuffix}",
                'email' => ($index === 1 ? 'owner' : "user{$userSuffix}")."{$suffix}@performance.test",
                'phone' => '091'.str_pad($suffix.$userSuffix, 8, '0', STR_PAD_LEFT),
            ]));
        }

        return $users;
    }

    private function seedAccounts(Tenant $tenant, string $suffix, TenantUser $creator, FinancialAccountTypes $type, Currency $currency): array
    {
        $accounts = [];

        foreach (['Main Cash', 'Counter Cash', 'Bank'] as $index => $name) {
            $accounts[] = FinancialAccount::query()->create(FinancialAccountFactory::new()->raw([
                'tenant_id' => $tenant->id,
                'account_type_id' => $type->id,
                'currency_id' => $currency->id,
                'account_number' => "PERF-{$suffix}-".($index + 1),
                'account_name' => $name,
                'account_code' => "PA{$suffix}".($index + 1),
                'is_default' => $index === 0,
                'created_by' => $creator->id,
            ]));
        }

        return $accounts;
    }

    private function seedSettings(Tenant $tenant, string $timezone, Currency $currency): void
    {
        $now = now();
        DB::table('tenant_settings')->insert([
            ['tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => $timezone, 'category' => 'regional', 'default_currency_id' => null, 'reporting_currency_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['tenant_id' => $tenant->id, 'key' => 'default_tenant_user_password', 'value' => config('performance-testing.password'), 'category' => 'security', 'default_currency_id' => null, 'reporting_currency_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['tenant_id' => $tenant->id, 'key' => 'currency_preferences', 'value' => 'base', 'category' => 'finance', 'default_currency_id' => $currency->id, 'reporting_currency_id' => $currency->id, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    private function seedCustomers(Tenant $tenant, string $suffix, array $users, int $count): array
    {
        $chunkSize = (int) config('performance-testing.chunk_size');

        for ($offset = 1; $offset <= $count; $offset += $chunkSize) {
            $rows = [];
            $limit = min($count, $offset + $chunkSize - 1);

            for ($index = $offset; $index <= $limit; $index++) {
                $number = str_pad((string) $index, 7, '0', STR_PAD_LEFT);
                $rows[] = TenantCustomerFactory::new()->raw([
                    'tenant_id' => $tenant->id,
                    'code' => "PERFC{$suffix}{$number}",
                    'nrc' => "PERF/C/{$suffix}{$number}",
                    'email' => "customer{$suffix}{$number}@performance.test",
                    'phone' => '092'.substr($suffix.$number, -8),
                    'created_by' => $users[($index - 1) % count($users)]->id,
                    'created_at' => now()->subDays($index % 730),
                    'updated_at' => now()->subDays($index % 730),
                ]);
            }

            DB::table('tenant_customers')->insert($rows);
        }

        return DB::table('tenant_customers')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('id')->all();
    }

    private function seedSlips(Tenant $tenant, string $suffix, array $users, array $accounts, array $customerIds, $interestTypes, string $timezone, int $count): void
    {
        $chunkSize = (int) config('performance-testing.chunk_size');
        $interestCycle = ['monthly', 'monthly', 'weekly', 'daily'];

        for ($offset = 1; $offset <= $count; $offset += $chunkSize) {
            $rows = [];
            $limit = min($count, $offset + $chunkSize - 1);

            for ($index = $offset; $index <= $limit; $index++) {
                $number = str_pad((string) $index, 8, '0', STR_PAD_LEFT);
                $statusRoll = $index % 100;
                $status = $statusRoll < 70 ? 'active' : ($statusRoll < 85 ? 'redeemed' : 'expired');
                $createdAt = CarbonImmutable::now()->subDays($index % 730)->setTime(9, 0);
                $interestCode = $interestCycle[$index % count($interestCycle)];
                $firstPeriodStart = $createdAt->setTimezone($timezone)->startOfDay();
                $nextPeriodStart = $interestCode === 'monthly'
                    ? $firstPeriodStart->addMonthNoOverflow()
                    : $firstPeriodStart->addDays($interestCode === 'weekly' ? 7 : 1);
                $hasPaidInterest = $status === 'redeemed' || $index % 3 === 0;
                $expireAt = match ($status) {
                    'active' => CarbonImmutable::now()->addDays(30 + ($index % 300))->startOfDay(),
                    'expired' => CarbonImmutable::now()->subDays(1 + ($index % 365))->startOfDay(),
                    default => $createdAt->addMonths(3)->startOfDay(),
                };

                $rows[] = PawnLoanContractSlipFactory::new()->raw([
                    'tenant_id' => $tenant->id,
                    'slip_no' => "PERF-LS-{$suffix}-{$number}",
                    'customer_id' => $customerIds[($index - 1) % count($customerIds)],
                    'account_id' => $accounts[$index % count($accounts)]->id,
                    'interest_type_id' => $interestTypes[$interestCode]->id,
                    'expire_at' => $expireAt,
                    'last_interest_added_at' => ($hasPaidInterest && $status === 'active' ? $nextPeriodStart : $firstPeriodStart)->utc(),
                    'last_interest_paid_at' => $hasPaidInterest ? $nextPeriodStart->utc() : null,
                    'status' => $status,
                    'created_by' => $users[$index % count($users)]->id,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
            }

            DB::table('pawn_loan_contract_slips')->insert($rows);
            $slipNumbers = array_column($rows, 'slip_no');
            $slips = DB::table('pawn_loan_contract_slips')->whereIn('slip_no', $slipNumbers)->get()->keyBy('slip_no');
            $this->seedSlipRelations($tenant, $suffix, $slips, $users, $accounts, $interestTypes, $timezone);
        }
    }

    private function seedSlipRelations(Tenant $tenant, string $suffix, $slips, array $users, array $accounts, $interestTypes, string $timezone): void
    {
        $items = [];
        $interests = [];
        $debts = [];
        $redemptions = [];
        $transactions = [];

        foreach ($slips->values() as $position => $slip) {
            $creator = $users[$position % count($users)];
            $account = $accounts[$position % count($accounts)];
            $sequence = (int) substr($slip->slip_no, -8);
            $interestType = $interestTypes->firstWhere('id', $slip->interest_type_id);
            $duration = $interestType->code === 'daily' ? 1 : ($interestType->code === 'weekly' ? 7 : 30);
            $start = CarbonImmutable::parse($slip->created_at, $timezone)->startOfDay();
            $nextStart = $interestType->code === 'monthly' ? $start->addMonthNoOverflow() : $start->addDays($duration);
            $amount = round((float) $slip->loan_amount * (float) $slip->interest_rate / 100, 2);
            $isPaid = $slip->status === 'redeemed' || $sequence % 3 === 0;

            for ($itemIndex = 1; $itemIndex <= 1 + ($sequence % 3); $itemIndex++) {
                $items[] = PawnCollateralItemFactory::new()->raw([
                    'tenant_id' => $tenant->id,
                    'code' => "PERFI{$suffix}".str_pad((string) $sequence, 8, '0', STR_PAD_LEFT).$itemIndex,
                    'loan_contract_id' => $slip->id,
                    'item_status' => $slip->status === 'redeemed' ? 'redeemed' : 'pawned',
                    'created_at' => $slip->created_at,
                    'updated_at' => $slip->created_at,
                ]);
            }

            $interests[] = PawnInterestPaymentFactory::new()->raw([
                'tenant_id' => $tenant->id,
                'slip_id' => $slip->id,
                'created_account_id' => $account->id,
                'accept_account_id' => $isPaid ? $account->id : null,
                'calculated_interest' => $amount,
                'payment_amount' => $isPaid ? $amount : 0,
                'payment_at' => $isPaid ? $nextStart : null,
                'start_period_at' => $start->utc(),
                'end_period_at' => $nextStart->subDay()->endOfDay()->utc(),
                'period_timezone' => $timezone,
                'is_paid' => $isPaid,
                'created_by' => $creator->id,
                'created_at' => $slip->created_at,
                'updated_at' => $slip->created_at,
            ]);

            if ($isPaid && $slip->status === 'active') {
                $secondEnd = $interestType->code === 'monthly' ? $nextStart->addMonthNoOverflow() : $nextStart->addDays($duration);
                $interests[] = PawnInterestPaymentFactory::new()->raw([
                    'tenant_id' => $tenant->id,
                    'slip_id' => $slip->id,
                    'created_account_id' => $account->id,
                    'accept_account_id' => null,
                    'calculated_interest' => $amount,
                    'payment_amount' => 0,
                    'payment_at' => null,
                    'start_period_at' => $nextStart->utc(),
                    'end_period_at' => $secondEnd->subDay()->endOfDay()->utc(),
                    'period_timezone' => $timezone,
                    'is_paid' => false,
                    'created_by' => $creator->id,
                    'created_at' => $slip->created_at,
                    'updated_at' => $slip->created_at,
                ]);
            }

            if ($slip->status !== 'redeemed' && $sequence % 20 === 0) {
                $debtAmount = max(1000, round($amount / 2, 2));
                $debts[] = TenantDebtFactory::new()->raw([
                    'tenant_id' => $tenant->id,
                    'code' => "PERFD{$suffix}".str_pad((string) $sequence, 8, '0', STR_PAD_LEFT),
                    'created_account_id' => $account->id,
                    'accept_account_id' => $account->id,
                    'slip_id' => $slip->id,
                    'customer_id' => $slip->customer_id,
                    'amount' => $debtAmount,
                    'principal_balance' => $debtAmount,
                    'apply_interest' => true,
                    'interest_rate' => 5,
                    'interest_type_id' => $interestTypes->firstWhere('code', 'monthly')->id,
                    'interest_anchor_at' => CarbonImmutable::parse($slip->created_at, $timezone)->subMonth()->utc(),
                    'accepted_by' => $creator->id,
                    'created_by' => $creator->id,
                    'created_at' => $slip->created_at,
                    'updated_at' => $slip->created_at,
                ]);
            }

            if ($slip->status === 'redeemed') {
                $redemptions[] = PawnRedemptionFactory::new()->raw([
                    'tenant_id' => $tenant->id,
                    'slip_number' => 'RD-'.$slip->slip_no,
                    'slip_id' => $slip->id,
                    'account_id' => $account->id,
                    'gross_amount' => (float) $slip->loan_amount + $amount,
                    'net_amount' => (float) $slip->loan_amount + $amount,
                    'interest_amount' => $amount,
                    'received_amount' => (float) $slip->loan_amount + $amount,
                    'redemption_at' => CarbonImmutable::parse($slip->created_at)->addMonths(2),
                    'created_by' => $creator->id,
                    'created_at' => $slip->created_at,
                    'updated_at' => $slip->created_at,
                ]);
            }

            $transactions[] = TenantAccountingTransactionFactory::new()->raw([
                'tenant_id' => $tenant->id,
                'business_date' => CarbonImmutable::parse($slip->created_at)->toDateString(),
                'transaction_direction' => 'outgoing',
                'accounting_category' => 'asset',
                'amount' => $slip->loan_amount,
                'currency_id' => $account->currency_id,
                'reporting_amount' => $slip->loan_amount,
                'exchange_rate' => 1,
                'description' => 'Pawn loan principal',
                'reference_id' => $slip->id,
                'reference_type' => 'App\\Models\\PawnModule\\PawnLoanContractSlip',
                'occurred_at' => $slip->created_at,
                'created_by' => $creator->id,
                'created_at' => $slip->created_at,
                'updated_at' => $slip->created_at,
            ]);
        }

        foreach ([
            'pawn_collateral_items' => $items,
            'pawn_interest_payments' => $interests,
            'tenant_debts' => $debts,
            'pawn_redemptions' => $redemptions,
            'tenant_accounting_transactions' => $transactions,
        ] as $table => $rows) {
            if ($rows !== []) {
                DB::table($table)->insert($rows);
            }
        }

        $this->seedDebtRelations($tenant, $debts, $users, $accounts, $timezone);
    }

    /** Seed debt interest rows, payments, and payment allocations after debt IDs exist. */
    private function seedDebtRelations(Tenant $tenant, array $debtRows, array $users, array $accounts, string $timezone): void
    {
        if ($debtRows === []) {
            return;
        }

        $codes = array_column($debtRows, 'code');
        $debts = DB::table('tenant_debts')->where('tenant_id', $tenant->id)->whereIn('code', $codes)->orderBy('id')->get();
        $accrualRows = [];
        foreach ($debts as $debt) {
            $accrualCount = (int) config('performance-testing.debt_accruals_per_debt');
            for ($index = 0; $index < $accrualCount; $index++) {
                $start = CarbonImmutable::parse($debt->interest_anchor_at ?: $debt->created_at, $timezone)
                    ->addMonths($index)
                    ->startOfDay();
                $interest = round((float) $debt->principal_balance * 0.05, 2);
                $accrualRows[] = array_merge(TenantDebtInterestAccrual::factory()->raw([
                    'principal_amount' => $debt->principal_balance,
                    'calculated_interest' => $interest,
                    'paid_amount' => $index === 0 ? $interest : 0,
                    'start_period_at' => $start->utc(),
                    'end_period_at' => $start->addMonth()->subSecond()->utc(),
                    'period_timezone' => $timezone,
                    'is_paid' => $index === 0,
                ]), [
                    'tenant_id' => $tenant->id,
                    'debt_id' => $debt->id,
                    'created_at' => $debt->created_at,
                    'updated_at' => $debt->updated_at,
                ]);
            }
        }
        if ($accrualRows !== []) {
            DB::table('tenant_debt_interest_accruals')->insert($accrualRows);
        }

        $accruals = DB::table('tenant_debt_interest_accruals')->where('tenant_id', $tenant->id)->whereIn('debt_id', $debts->pluck('id'))->orderBy('id')->get()->groupBy('debt_id');
        $paymentRows = [];
        foreach ($debts as $debt) {
            $paymentCount = (int) config('performance-testing.debt_payments_per_debt');
            for ($index = 1; $index <= $paymentCount; $index++) {
                $paymentRows[] = array_merge(TenantDebtPayment::factory()->raw([
                    'code' => "PERFDP-{$tenant->id}-{$debt->id}-{$index}",
                    'payment_amount' => 1_000,
                    'principal_paid' => 1_000,
                    'interest_paid' => 0,
                    'payment_at' => CarbonImmutable::parse($debt->created_at, 'UTC')->addDays(10 + $index),
                ]), [
                    'tenant_id' => $tenant->id,
                    'debt_id' => $debt->id,
                    'accept_account_id' => $accounts[0]->id,
                    'created_by' => $users[0]->id,
                    'created_at' => $debt->created_at,
                    'updated_at' => $debt->updated_at,
                ]);
            }
        }
        if ($paymentRows !== []) {
            DB::table('tenant_debt_payments')->insert($paymentRows);
        }

        $payments = DB::table('tenant_debt_payments')->where('tenant_id', $tenant->id)->whereIn('debt_id', $debts->pluck('id'))->orderBy('id')->get();
        $allocations = [];
        foreach ($payments as $payment) {
            $accrual = $accruals->get($payment->debt_id)?->first();
            if ($accrual === null) {
                continue;
            }
            $allocations[] = array_merge(TenantDebtPaymentAllocation::factory()->raw([
                'amount' => $payment->interest_paid,
            ]), [
                'tenant_id' => $tenant->id,
                'payment_id' => $payment->id,
                'accrual_id' => $accrual->id,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        }
        if ($allocations !== []) {
            DB::table('tenant_debt_payment_allocations')->insert($allocations);
        }
    }
}
