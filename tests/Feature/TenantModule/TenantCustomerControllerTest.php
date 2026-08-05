<?php

namespace Tests\Feature\TenantModule;

use App\Models\CoreModule\TenantCustomer;
use App\Models\CoreModule\TenantRole;
use App\Models\CoreModule\TenantUser;
use App\Models\PlatformModule\PlatformUser;
use App\Models\PlatformModule\Tenant;
use App\Models\PlatformModule\TenantLicense;
use App\Support\TenantContext;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantCustomerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PackageSeeder::class);
    }

    public function test_it_creates_customer_and_persists_normalized_nrc(): void
    {
        [$tenant, $tenantUser] = $this->tenantUserContext(['access_all']);
        $payload = $this->validCustomerPayload([
            'nrc_state' => '၁',
            'nrc_township' => config('nrc.Kachin.townships.KaMaTa.code_mm'),
            'nrc_citizen' => 'နိုင်',
            'nrc_number' => '၁၂၃၄၅၆',
        ]);

        $response = $this->tenantPostJson($tenant, '/api/tenant/customers', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.customer.name', 'Mg Mg')
            ->assertJsonPath('data.customer.nrc', '1/KaMaTa(N)123456')
            ->assertJsonPath('data.customer.created_by', $tenantUser->id);

        $this->assertDatabaseHas('tenant_customers', [
            'tenant_id' => $tenant->id,
            'name' => 'Mg Mg',
            'nrc' => '1/KaMaTa(N)123456',
            'email' => 'mgmg@example.com',
            'phone' => '0911111111',
            'address' => 'Yangon',
            'trust_score' => 3,
            'note' => 'Trusted',
            'created_by' => $tenantUser->id,
            'is_deleted' => false,
        ]);

        $customerId = $response->json('data.customer.id');

        $this->assertDatabaseHas('tenant_audit_logs', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $tenantUser->id,
            'action' => 'tenant_customer.created',
            'target_type' => TenantCustomer::class,
            'target_id' => $customerId,
        ]);
    }

    public function test_it_rejects_duplicate_active_customer_create(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $created = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated();

        $duplicate = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload([
            'name' => 'Duplicate',
            'email' => 'different@example.com',
        ]));

        $duplicate->assertConflict()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('tenant_customers', 1);
    }

    public function test_it_creates_new_customer_when_matching_customer_is_deleted(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $deleted = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated();

        $this->tenantDeleteJson($tenant, '/api/tenant/customers/'.$deleted->json('data.customer.code'))
            ->assertOk();

        $created = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated()
            ->assertJsonPath('data.created', true);

        $this->assertNotSame($deleted->json('data.customer.id'), $created->json('data.customer.id'));
        $this->assertDatabaseCount('tenant_customers', 2);

        $this->tenantGetJson($tenant, '/api/tenant/customers')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $created->json('data.customer.id'));
    }

    public function test_it_validates_customer_create_payload(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $response = $this->tenantPostJson($tenant, '/api/tenant/customers', [
            'email' => 'not-an-email',
            'phone' => str_repeat('1', 31),
            'trust_score' => 256,
            'nrc_state' => '1',
            'nrc_township' => 'LaKaNa',
            'nrc_citizen' => 'BAD',
            'nrc_number' => '12345',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'data' => [
                    'errors' => [
                        'name',
                        'email',
                        'phone',
                        'trust_score',
                        '_nrc',
                    ],
                ],
            ]);
    }

    public function test_it_requires_all_nrc_fields_or_none(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $response = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload([
            'nrc_state' => '1',
            'nrc_township' => null,
            'nrc_citizen' => 'N',
            'nrc_number' => '123456',
        ]));

        $response->assertUnprocessable()
            ->assertJsonPath('data.errors._nrc.0', 'NRC fields must be all filled or all empty.');
    }

    public function test_it_validates_index_query_parameters(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $this->tenantGetJson($tenant, '/api/tenant/customers?per_page=101&search='.str_repeat('a', 121))
            ->assertUnprocessable()
            ->assertJsonStructure([
                'data' => [
                    'errors' => [
                        'per_page',
                        'search',
                    ],
                ],
            ]);
    }

    public function test_it_lists_searches_and_shows_customers(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $first = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload([
            'name' => 'Aye Aye',
            'email' => 'aye@example.com',
            'phone' => '0911111111',
            'address' => 'Yangon',
            'nrc_number' => '100001',
        ]))->assertCreated();

        $second = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload([
            'name' => 'Ko Ko',
            'email' => 'koko@example.com',
            'phone' => '0922222222',
            'address' => 'Mandalay',
            'nrc_number' => '100002',
        ]))->assertCreated();

        $this->tenantGetJson($tenant, '/api/tenant/customers?search=mandalay')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', $second->json('data.customer.id'));

        $this->tenantGetJson($tenant, '/api/tenant/customers/'.$first->json('data.customer.code'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Aye Aye');
    }

    public function test_it_updates_customer_and_writes_audit_log(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $created = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated();

        $response = $this->tenantPutJson($tenant, '/api/tenant/customers/'.$created->json('data.customer.code'), [
            'name' => 'After',
            'email' => 'after@example.com',
            'phone' => '0999999999',
            'address' => 'Mandalay',
            'trust_score' => 5,
            'note' => 'Updated',
            'nrc_state' => '1',
            'nrc_township' => 'KaMaNa',
            'nrc_citizen' => 'E',
            'nrc_number' => '654321',
            'update_key' => $created->json('data.customer.update_key'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'After')
            ->assertJsonPath('data.nrc', '1/KaMaNa(E)654321')
            ->assertJsonPath('data.trust_score', 5);

        $this->assertDatabaseHas('tenant_customers', [
            'id' => $created->json('data.customer.id'),
            'name' => 'After',
            'email' => 'after@example.com',
            'phone' => '0999999999',
            'address' => 'Mandalay',
            'trust_score' => 5,
            'note' => 'Updated',
            'nrc' => '1/KaMaNa(E)654321',
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'tenant_customer.updated',
            'target_type' => TenantCustomer::class,
            'target_id' => $created->json('data.customer.id'),
        ]);
    }

    public function test_it_validates_customer_update_payload(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);
        $created = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated();

        $this->tenantPutJson($tenant, '/api/tenant/customers/'.$created->json('data.customer.code'), [
            'email' => 'invalid',
            'trust_score' => -1,
            'update_key' => -1,
        ])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'data' => [
                    'errors' => [
                        'email',
                        'trust_score',
                        'update_key',
                    ],
                ],
            ]);

        $this->tenantPutJson($tenant, '/api/tenant/customers/'.$created->json('data.customer.code'), [
            'nrc_state' => '20',
            'nrc_township' => 'KaMaTa',
            'nrc_citizen' => 'N',
            'nrc_number' => '123456',
            'update_key' => $created->json('data.customer.update_key'),
        ])
            ->assertUnprocessable()
            ->assertJsonStructure([
                'data' => [
                    'errors' => [
                        '_nrc',
                    ],
                ],
            ]);
    }

    public function test_it_clears_submitted_nullable_fields_and_preserves_omitted_fields(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);
        $created = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated();

        $response = $this->tenantPutJson($tenant, '/api/tenant/customers/'.$created->json('data.customer.code'), [
            'email' => null,
            'phone' => null,
            'address' => null,
            'note' => null,
            'nrc_state' => null,
            'nrc_township' => null,
            'nrc_citizen' => null,
            'nrc_number' => null,
            'update_key' => $created->json('data.customer.update_key'),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Mg Mg')
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.note', null)
            ->assertJsonPath('data.nrc', null)
            ->assertJsonPath('data.update_key', 1);

        $this->assertDatabaseHas('tenant_customers', [
            'id' => $created->json('data.customer.id'),
            'name' => 'Mg Mg',
            'email' => null,
            'phone' => null,
            'address' => null,
            'note' => null,
            'nrc' => null,
            'update_key' => 1,
        ]);
    }

    public function test_it_soft_deletes_customer(): void
    {
        [$tenant] = $this->tenantUserContext(['access_all']);

        $created = $this->tenantPostJson($tenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated();

        $this->tenantDeleteJson($tenant, '/api/tenant/customers/'.$created->json('data.customer.code'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('tenant_customers', [
            'id' => $created->json('data.customer.id'),
        ]);

        $this->assertDatabaseHas('tenant_customers', [
            'id' => $created->json('data.customer.id'),
            'is_deleted' => true,
        ]);

        $this->assertDatabaseHas('tenant_audit_logs', [
            'action' => 'tenant_customer.deleted',
            'target_type' => TenantCustomer::class,
            'target_id' => $created->json('data.customer.id'),
        ]);
    }

    public function test_it_rejects_user_without_required_permission(): void
    {
        [$tenant] = $this->tenantUserContext([]);

        $this->tenantGetJson($tenant, '/api/tenant/customers')
            ->assertForbidden();
    }

    public function test_it_does_not_access_customer_from_another_tenant(): void
    {
        [$firstTenant] = $this->tenantUserContext(['access_all'], 'first');
        $created = $this->tenantPostJson($firstTenant, '/api/tenant/customers', $this->validCustomerPayload())
            ->assertCreated();

        [$secondTenant] = $this->tenantUserContext(['access_all'], 'second');

        $this->tenantGetJson($secondTenant, '/api/tenant/customers/'.$created->json('data.customer.code'))
            ->assertNotFound();
    }

    private function validCustomerPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Mg Mg',
            'nrc_state' => '1',
            'nrc_township' => 'KaMaTa',
            'nrc_citizen' => 'N',
            'nrc_number' => '123456',
            'email' => 'mgmg@example.com',
            'phone' => '0911111111',
            'address' => 'Yangon',
            'trust_score' => 3,
            'note' => 'Trusted',
        ], $overrides);
    }

    private function tenantGetJson(Tenant $tenant, string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant-Code', $tenant->tenant_code)->getJson($uri);
    }

    private function tenantPostJson(Tenant $tenant, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant-Code', $tenant->tenant_code)->postJson($uri, $data);
    }

    private function tenantPutJson(Tenant $tenant, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant-Code', $tenant->tenant_code)->putJson($uri, $data);
    }

    private function tenantDeleteJson(Tenant $tenant, string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant-Code', $tenant->tenant_code)->deleteJson($uri);
    }

    private function tenantUserContext(array $permissions, string $suffix = 'demo'): array
    {
        $tenant = $this->createTenant($suffix);

        TenantLicense::query()->create([
            'tenant_id' => $tenant->id,
            'license_key' => 'CUSTOMERTEST'.strtoupper($suffix),
            'plan_type' => 'basic',
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'activated_at' => now(),
        ]);

        $role = TenantRole::query()->create([
            'tenant_id' => null,
            'name' => 'Customer Role '.$suffix,
            'description' => 'Customer test role',
            'is_default' => false,
            'permissions' => $permissions,
        ]);

        $tenantUser = TenantUser::query()->withoutGlobalScope('tenant')->create([
            'code' => 'TU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'tenant_id' => $tenant->id,
            'role_id' => $role->id,
            'username' => 'USER'.strtoupper($suffix),
            'name' => 'Tenant User '.$suffix,
            'nrc' => '12/PaTaNa(N)000001',
            'email' => "tenant-user-{$suffix}@example.com",
            'phone' => '0955'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
            'is_deleted' => false,
        ]);

        app(TenantContext::class)->set($tenant);
        Sanctum::actingAs($tenantUser, [], 'tenantuser');

        return [$tenant, $tenantUser];
    }

    private function createTenant(string $suffix): Tenant
    {
        $platformUser = PlatformUser::query()->create([
            'code' => 'PU'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'name' => 'Owner User '.$suffix,
            'email' => "owner-{$suffix}@example.com",
            'phone' => '0999'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'password' => 'secret123',
            'status' => 'active',
        ]);

        return Tenant::query()->create([
            'platform_user_id' => $platformUser->id,
            'name' => 'Demo Tenant '.$suffix,
            'tenant_code' => 'demo-tenant-'.$suffix,
            'subdomain' => 'demo-subdomain-'.$suffix,
            'status' => 'active',
        ]);
    }
}
