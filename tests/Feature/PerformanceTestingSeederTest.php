<?php

namespace Tests\Feature;

use App\Models\CoreModule\TenantCustomer;
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
        config()->set('performance-testing.slip_count', 8);
        config()->set('performance-testing.chunk_size', 3);
    }

    public function test_it_seeds_a_tenant_consistent_performance_dataset(): void
    {
        $this->seed(PerformanceTestingSeeder::class);

        $this->assertDatabaseHas('tenants', ['tenant_code' => 'perf-tenant-001']);
        $tenantId = (int) $this->getConnection()->table('tenants')->where('tenant_code', 'perf-tenant-001')->value('id');
        $this->assertDatabaseCount('tenant_customers', 5);
        $this->assertDatabaseCount('pawn_loan_contract_slips', 8);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenantId, 'email' => 'owner001@performance.test']);
        $this->assertSame(0, TenantCustomer::query()->withoutGlobalScopes()->where('tenant_id', '!=', $tenantId)->count());
        $this->assertGreaterThanOrEqual(8, $this->getConnection()->table('pawn_interest_payments')->where('tenant_id', $tenantId)->count());
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

    public function test_performance_seeder_fails_outside_testing(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        (new PerformanceTestingSeeder())->run();
    }
}
