<?php

namespace Tests\Feature;

use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantBusinessLoan;
use App\Models\CoreModule\TenantDebtInterestAccrual;
use App\Models\CoreModule\TenantDebtPayment;
use App\Models\CoreModule\TenantExpense;
use App\Models\CoreModule\TenantLender;
use App\Models\CoreModule\TenantPerson;
use App\Models\CoreModule\TenantScheduledExpense;
use App\Models\CoreModule\TenantScheduledExpenseOccurrence;
use App\Models\PlatformModule\PlatformUser;
use Database\Seeders\PerformanceTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PerformanceTestingSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Small counts keep the relationship and rerun checks fast.
        config()->set('performance-testing.tenant_count', 1);
        config()->set('performance-testing.users_per_tenant', 2);
        config()->set('performance-testing.customers_per_tenant', 5);
        config()->set('performance-testing.slip_count', 40);
        config()->set('performance-testing.lenders_per_tenant', 2);
        config()->set('performance-testing.business_loans_per_tenant', 3);
        config()->set('performance-testing.business_loan_accruals_per_loan', 2);
        config()->set('performance-testing.business_loan_payments_per_loan', 1);
        config()->set('performance-testing.scheduled_expenses_per_tenant', 2);
        config()->set('performance-testing.debt_accruals_per_debt', 2);
        config()->set('performance-testing.debt_payments_per_debt', 1);
        config()->set('performance-testing.scheduled_occurrences_per_expense', 2);
        config()->set('performance-testing.chunk_size', 3);
    }

    public function test_it_seeds_a_tenant_consistent_performance_dataset(): void
    {
        $this->seed(PerformanceTestingSeeder::class);

        $this->assertDatabaseHas('tenants', ['tenant_code' => 'perf-tenant-001']);
        $tenantId = (int) $this->getConnection()->table('tenants')->where('tenant_code', 'perf-tenant-001')->value('id');
        $this->assertDatabaseCount('tenant_customers', 5);
        $this->assertDatabaseCount('pawn_loan_contract_slips', 40);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenantId, 'email' => 'owner001@performance.test']);
        $this->assertSame(0, TenantCustomer::query()->withoutGlobalScopes()->where('tenant_id', '!=', $tenantId)->count());
        $this->assertGreaterThanOrEqual(40, $this->getConnection()->table('pawn_interest_payments')->where('tenant_id', $tenantId)->count());
        $this->assertDatabaseCount('tenant_people', 2);
        $this->assertDatabaseCount('tenant_lenders', 2);
        $this->assertDatabaseCount('tenant_business_loans', 3);
        $this->assertDatabaseCount('tenant_business_loan_interest_accruals', 6);
        $this->assertDatabaseCount('tenant_business_loan_payments', 3);
        $this->assertDatabaseCount('tenant_business_loan_payment_allocations', 3);
        $this->assertDatabaseCount('tenant_debts', 2);
        $this->assertDatabaseCount('tenant_debt_interest_accruals', 4);
        $this->assertDatabaseCount('tenant_debt_payments', 2);
        $this->assertDatabaseCount('tenant_debt_payment_allocations', 2);
        $this->assertDatabaseCount('tenant_scheduled_expenses', 2);
        $this->assertDatabaseCount('tenant_scheduled_expense_occurrences', 4);
        $this->assertDatabaseCount('tenant_expenses', 2);
    }

    public function test_rerun_replaces_only_marked_performance_data(): void
    {
        PlatformUser::query()->create([
            'code' => 'UNRELATED001',
            'name' => 'Unrelated Test User',
            'email' => 'unrelated@example.test',
            'password' => 'Password123!',
            'status' => 'active',
        ]);

        $this->seed(PerformanceTestingSeeder::class);
        $this->seed(PerformanceTestingSeeder::class);

        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('tenant_customers', 5);
        $this->assertDatabaseHas('platform_users', ['email' => 'unrelated@example.test']);
    }

    public function test_performance_factory_fails_outside_testing(): void
    {
        $this->app['env'] = 'local';

        $this->expectException(RuntimeException::class);
        TenantCustomer::factory()->make();
    }

    public function test_new_performance_factories_fail_outside_testing(): void
    {
        $this->app['env'] = 'local';

        foreach ([
            TenantPerson::class,
            TenantLender::class,
            TenantBusinessLoan::class,
            TenantDebtInterestAccrual::class,
            TenantDebtPayment::class,
            TenantScheduledExpense::class,
            TenantScheduledExpenseOccurrence::class,
            TenantExpense::class,
        ] as $model) {
            try {
                $model::factory()->make();
                $this->fail("{$model} factory was usable outside testing.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('APP_ENV=testing', $exception->getMessage());
            }
        }
    }

    public function test_performance_seeder_fails_outside_testing(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        (new PerformanceTestingSeeder())->run();
    }
}
