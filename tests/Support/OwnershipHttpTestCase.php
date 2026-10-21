<?php

namespace Tests\Support;

use App\Models\PlatformModule\{Feature, Package, PackageFeature, TenantLicense};
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** Real auth, tenant, permission and feature middleware; all records roll back with the fixture. */
abstract class OwnershipHttpTestCase extends OwnershipQuantityTestCase
{
    protected function login(array $permissions = ['list_owned_item', 'manage_owned_item']): void
    {
        // Reuse package metadata but attach a private copied plan so gate tests do not change
        // another tenant's plan. No middleware is disabled or replaced with an authorization mock.
        $this->seed(\Database\Seeders\PackageSeeder::class);
        $base = Package::query()->where('code', 'basic')->firstOrFail();
        $plan = $base->replicate();
        $plan->code = 'ownership-test-'.bin2hex(random_bytes(5));
        $plan->save();
        foreach (['ownership_management', 'inventory_management'] as $code) {
            $feature = Feature::query()->where('code', $code)->firstOrFail();
            PackageFeature::query()->create(['package_id' => $plan->id, 'feature_id' => $feature->id,
                'is_enabled' => true]);
        }
        TenantLicense::query()->create(['tenant_id' => $this->ownershipFixture['tenant']->id,
            'license_key' => 'OT-'.bin2hex(random_bytes(6)), 'plan_id' => $plan->id, 'plan_type' => $plan->code,
            'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'activated_at' => now()]);
        $this->ownershipFixture['role']->update(['permissions' => $permissions]);
        $this->ownershipFixture['actor']->unsetRelations();
        Sanctum::actingAs($this->ownershipFixture['actor'], [], 'tenantuser');
        $this->withHeader('X-Tenant-Code', $this->ownershipFixture['tenant']->tenant_code);
    }

    protected function linkUrl(array $goods): string
    {
        return '/api/tenant/ownership/items/'.$goods['owned']->code.'/lots/'.$goods['lot']->code.'/receipt-links';
    }

    protected function linkPayload(array $goods, array $receipt): array
    {
        // Capture the version once; retries must reuse the original payload, including its old version.
        return ['purchase_receipt_line_code' => $receipt['receiptLine']->code, 'quantity' => '1.000',
            'serialized_item_codes' => $receipt['inventory']['serialized_item_codes'],
            'update_key' => (int) $goods['owned']->fresh()->update_key];
    }

    protected function httpLink(array $goods, array $payload, ?string $key): TestResponse
    {
        $headers = $key === null ? [] : ['Idempotency-Key' => $key];
        $response = $this->postJson($this->linkUrl($goods), $payload, $headers);
        // Real middleware clears TenantContext after each request. Restore the original fixture
        // before direct-service assertions; never infer tenant identity from a supplied payload.
        $this->setTenant($this->ownershipFixture);
        return $response;
    }
}
