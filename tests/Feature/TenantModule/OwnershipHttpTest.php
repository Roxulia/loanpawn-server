<?php

namespace Tests\Feature\TenantModule;

use App\Models\PlatformModule\{Feature, PackageFeature, TenantLicense};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\OwnershipHttpTestCase;

class OwnershipHttpTest extends OwnershipHttpTestCase
{
    #[DataProvider('missingAccess')]
    public function test_read_requires_both_features_and_permission(?string $feature, bool $permission): void
    {
        // Remove one requirement at a time; valid authentication alone must not expose ownership.
        $this->login($permission ? ['list_owned_item'] : []);
        if ($feature !== null) {
            $license = TenantLicense::query()->where('tenant_id', $this->ownershipFixture['tenant']->id)->firstOrFail();
            PackageFeature::query()->where('package_id', $license->plan_id)
                ->where('feature_id', Feature::query()->where('code', $feature)->value('id'))->update(['is_enabled' => false]);
        }
        $before = $this->receiptState();
        $this->getJson('/api/tenant/ownership/items')->assertForbidden();
        $this->setTenant($this->ownershipFixture);
        $this->assertSame($before, $this->receiptState());
    }

    public function test_manual_gate_blocks_privileged_payload(): void
    {
        // Even create permission and attacker-supplied paid/bypass flags cannot open the inactive gate.
        $this->login(['list_owned_item', 'create_owned_item']);
        $before = $this->receiptState();
        $this->postJson('/api/tenant/ownership/acquisitions', ['paid' => true, 'trusted' => true,
            'tenant_id' => $this->ownershipFixture['tenant']->id])->assertForbidden();
        $this->setTenant($this->ownershipFixture);
        $this->assertSame($before, $this->receiptState());
    }

    public function test_list_permission_reads_candidates_but_cannot_link(): void
    {
        $goods = $this->goods('QUANTITY', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000');
        $this->login(['list_owned_item']);
        $this->getJson(str_replace('/receipt-links', '/receipt-link-candidates', $this->linkUrl($goods)))->assertOk();
        $this->setTenant($this->ownershipFixture);
        $before = $this->receiptState();
        $this->httpLink($goods, $this->linkPayload($goods, $receipt), 'no-permission')->assertForbidden();
        $this->assertSame($before, $this->receiptState());
    }

    public function test_link_success_replay_stale_conflict_and_missing_key(): void
    {
        // A successful logical link advances once. The identical key returns the original success
        // before checking its now-stale version; a fresh key with that version must instead conflict.
        $goods = $this->goods('QUANTITY', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000');
        $this->login();
        $payload = $this->linkPayload($goods, $receipt);
        $before = $this->receiptState();
        $this->httpLink($goods, $payload, null)->assertUnprocessable();
        $this->assertSame($before, $this->receiptState());
        $first = $this->httpLink($goods, $payload, 'link-original')->assertOk()->json();
        $this->assertSame($payload['update_key'] + 1, (int) $goods['owned']->fresh()->update_key);
        $after = $this->receiptState();
        $this->assertSame($first, $this->httpLink($goods, $payload, 'link-original')
            ->assertOk()->assertHeader('Idempotent-Replay', 'true')->json());
        $this->httpLink($goods, $payload, 'link-stale')->assertConflict()
            ->assertJsonPath('data.code', 'UPDATING_ALREADY_UPDATED_DATA');
        $this->assertSame($after, $this->receiptState());
    }

    public function test_foreign_path_is_not_found_and_cannot_select_tenant(): void
    {
        // A valid foreign lot is hidden even when its tenant ID and trusted flags are supplied.
        $home = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->goods('QUANTITY', '1.000', '12.34');
        $receipt = $this->receivePurchase($foreign['purchase'], '1.000');
        $payload = $this->linkPayload($foreign, $receipt) + ['tenant_id' => $this->ownershipFixture['tenant']->id, 'paid' => true];
        $this->setTenant($home);
        $this->login();
        $before = $this->receiptState();
        $this->httpLink($foreign, $payload, 'foreign-link')->assertNotFound();
        $this->assertSame($before, $this->receiptState());
    }

    public function test_current_version_can_link_remaining_quantity_after_conflict(): void
    {
        // Two received units permit two partial links. Refreshing after the first logical change
        // must allow the remaining unit; conflict recovery is not an automatic stale resubmission.
        $goods = $this->goods('QUANTITY', '2.000', '24.68');
        $receipt = $this->receivePurchase($goods['purchase'], '2.000');
        $this->login();
        $old = $this->linkPayload($goods, $receipt);
        $this->httpLink($goods, $old, 'first-part')->assertOk();
        $this->httpLink($goods, $old, 'stale-part')->assertConflict();
        $current = $this->linkPayload($goods, $receipt);
        $this->httpLink($goods, $current, 'last-part')->assertOk();
        $this->assertSame($old['update_key'] + 2, (int) $goods['owned']->fresh()->update_key);
        $this->assertSame('2.000', $this->lotData($goods)['received_backed_quantity']);
    }

    public static function missingAccess(): array
    {
        return ['ownership gate' => ['ownership_management', true], 'inventory gate' => ['inventory_management', true],
            'list permission' => [null, false]];
    }
}
