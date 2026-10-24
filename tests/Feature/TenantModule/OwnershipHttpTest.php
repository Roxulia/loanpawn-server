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

    public function test_reads_expose_separate_pages_with_exact_quantities(): void
    {
        // A paid unit awaiting receipt is owned but has no physical stock; reads must retain that distinction.
        $goods = $this->goods('QUANTITY', '1.000', '12.34');
        $this->login(['list_owned_item']);
        $url = '/api/tenant/ownership/items/'.$goods['owned']->code;
        $this->getJson('/api/tenant/ownership/items?per_page=1&holding=current&tracking_mode=QUANTITY')
            ->assertOk()->assertJsonPath('data.per_page', 1)->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.owned_quantity', '1.000')
            ->assertJsonPath('data.items.0.physical_quantity', '0.000');
        $this->getJson($url)->assertOk()->assertJsonPath('data.code', $goods['owned']->code)
            ->assertJsonPath('data.receipt_link_state', 'PENDING')->assertJsonMissingPath('data.lots');
        $this->getJson($url.'/lots?per_page=1')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', $goods['lot']->code)
            ->assertJsonPath('data.items.0.awaiting_receipt_quantity', '1.000');
        $this->getJson($url.'/movements?per_page=1')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.owned_delta', '1.000');
    }

    public function test_read_filters_are_validated_before_service_calls(): void
    {
        // Exercise the HTTP boundary for each paginated read; invalid limits must never become unbounded queries.
        $goods = $this->goods('QUANTITY', '1.000', '12.34');
        $this->login(['list_owned_item']);
        $url = '/api/tenant/ownership/items/'.$goods['owned']->code;
        foreach (['/api/tenant/ownership/items?per_page=101', '/api/tenant/ownership/items?holding=unknown',
            '/api/tenant/ownership/items?tracking_mode=unknown', $url.'/lots?page=0',
            $url.'/movements?per_page=0', str_replace('/receipt-links', '/receipt-link-candidates', $this->linkUrl($goods)).'?page=0'] as $path) {
            $this->getJson($path)->assertUnprocessable();
        }
    }

    public function test_serialized_candidates_and_link_use_public_piece_codes(): void
    {
        // Select one actual received piece through the read API, then link only that exact identity.
        $goods = $this->goods('SERIALIZED', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000', ['API-SERIAL-0']);
        $this->login();
        $url = str_replace('/receipt-links', '/receipt-link-candidates', $this->linkUrl($goods));
        $piece = $receipt['inventory']['serialized_item_codes'][0];
        $this->getJson($url)->assertOk()->assertJsonPath('data.items.0.purchase_receipt_line_code', $receipt['receiptLine']->code);
        $this->getJson($url.'?purchase_receipt_line_code='.$receipt['receiptLine']->code)
            ->assertOk()->assertJsonPath('data.items.0.code', $piece)->assertJsonPath('data.items.0.is_eligible', true);
        $this->setTenant($this->ownershipFixture);
        $this->httpLink($goods, $this->linkPayload($goods, $receipt), 'api-piece-link')
            ->assertOk()->assertJsonPath('data.serialized_item_codes.0', $piece);
        $this->getJson($url.'?purchase_receipt_line_code='.$receipt['receiptLine']->code)
            ->assertOk()->assertJsonPath('data.items.0.link_state', 'LINKED')->assertJsonPath('data.items.0.is_eligible', false);
    }

    public function test_payload_key_cannot_replace_header_or_bypass_validation(): void
    {
        // A body replay key and trusted flags cannot authorize a write; every rejected request leaves all ledgers unchanged.
        $goods = $this->goods('QUANTITY', '1.000', '12.34');
        $receipt = $this->receivePurchase($goods['purchase'], '1.000');
        $this->login();
        $payload = $this->linkPayload($goods, $receipt);
        $before = $this->receiptState();
        $this->httpLink($goods, $payload + ['idempotency_key' => 'body-key', 'paid' => true, 'trusted' => true], null)->assertUnprocessable();
        foreach ([['quantity' => '0.000'], ['quantity' => '1.0001'], ['update_key' => -1],
            ['serialized_item_codes' => ['duplicate', 'duplicate']]] as $invalid) {
            $this->httpLink($goods, array_replace($payload, $invalid), 'invalid-write')->assertUnprocessable();
        }
        $this->assertSame($before, $this->receiptState());
    }

    public function test_foreign_item_reads_do_not_expose_history(): void
    {
        // Restore the home tenant before authentication; real middleware must hide every foreign aggregate read.
        $home = $this->ownershipFixture;
        $this->createTenant();
        $foreign = $this->goods('QUANTITY', '1.000', '12.34');
        $this->setTenant($home);
        $this->login(['list_owned_item']);
        $url = '/api/tenant/ownership/items/'.$foreign['owned']->code;
        foreach ([$url, $url.'/lots', $url.'/movements',
            str_replace('/receipt-links', '/receipt-link-candidates', $this->linkUrl($foreign))] as $path) {
            $this->getJson($path)->assertNotFound();
        }
    }

    public static function missingAccess(): array
    {
        return ['ownership gate' => ['ownership_management', true], 'inventory gate' => ['inventory_management', true],
            'list permission' => [null, false]];
    }
}
