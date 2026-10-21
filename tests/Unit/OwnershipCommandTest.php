<?php

namespace Tests\Unit;

use App\DataObjects\RequestObjects\{InventoryReceiveRequest, OwnershipAcquisitionCreate, OwnershipReceiptLinkRequest, OwnershipSource};
use App\Exceptions\InvalidTenantRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** OWN-05c: command boundaries; source eligibility and record associations require integration tests. */
class OwnershipCommandTest extends TestCase
{
    public function test_acquisition_normalizes_public_codes(): void
    {
        // Preserve code case while trimming references and uppercasing the currency.
        $request = $this->acquisition(['inventory_item_code' => ' ITEM-A ', 'currency_code' => ' mmk ']);
        $this->assertSame('ITEM-A', $request->inventoryItemCode);
        $this->assertSame('MMK', $request->currencyCode);
    }

    public function test_acquisition_does_not_require_known_serials(): void
    {
        // Paying for serialized goods records exact quantity without inventing pieces before receipt.
        $request = OwnershipAcquisitionCreate::fromSource($this->source());
        $this->assertSame('3.000', $request->quantity);
        $this->assertArrayNotHasKey('serialized_item_codes', $request->toArray());
        $this->assertArrayNotHasKey('serialized_item_identifiers', $request->toArray());
    }

    public function test_internal_acquisition_uses_typed_source(): void
    {
        // This context represents resolver output, not proof that a real purchase was paid.
        $source = $this->source();
        $request = OwnershipAcquisitionCreate::fromSource($source);
        $this->assertSame($source, $request->source);
        $this->assertSame('PURCHASING', $source->sourceModule);
        $this->assertSame('PurchaseOrderLine', $source->sourceType);
        $this->assertSame('LINE-A', $source->sourceCode);
        $this->assertSame('ITEM-A', $request->inventoryItemCode);
        $this->assertSame('3.000', $request->quantity);
        $this->assertSame('12.3400', $request->unitCostBasis);
        $this->assertSame('MMK', $request->currencyCode);
        $this->assertSame('2026-10-21', $request->acquiredAt);
    }

    public function test_public_acquisition_cannot_hydrate_trusted_source(): void
    {
        // Even an explicitly injected typed object cannot grant authority through the public factory.
        $request = $this->acquisition(['source' => $this->source()]);
        $this->assertNull($request->source);
        $this->assertSame('ITEM-PUBLIC', $request->inventoryItemCode);
        $this->assertSame('1.000', $request->quantity);
    }

    #[DataProvider('privateFields')]
    public function test_public_acquisition_omits_private_fields(string $field, mixed $value): void
    {
        // The public factory allowlist cannot turn payload flags or nested arrays into trust.
        $request = $this->acquisition([$field => $value]);
        $this->assertNull($request->source);
        $this->assertArrayNotHasKey($field, $request->toArray());
    }

    public function test_link_normalizes_receipt_quantity_and_piece_codes(): void
    {
        // Reindex selected codes without sorting away the caller's explicit selection order.
        $request = $this->link(['purchase_receipt_line_code' => ' RECEIPT-A ', 'quantity' => '2',
            'serialized_item_codes' => [4 => ' PIECE-B ', 8 => ' PIECE-A ']], 'link-key');
        $this->assertSame('RECEIPT-A', $request->purchaseReceiptLineCode);
        $this->assertSame('2.000', $request->quantity);
        $this->assertSame(['PIECE-B', 'PIECE-A'], $request->serializedItemCodes);
        $this->assertSame(0, $request->updateKey);
        $this->assertSame('link-key', $request->idempotencyKey);
    }

    public function test_nonserialized_link_defaults_to_no_piece_selection(): void
    {
        // Tracking mode and piece-count association are resolved by the service, not this factory.
        $this->assertSame([], $this->link()->serializedItemCodes);
    }

    public function test_link_preserves_zero_code(): void
    {
        // A string zero is a valid nonblank reference, not a missing selection.
        $request = $this->link(['serialized_item_codes' => [' 0 ']]);
        $this->assertSame(['0'], $request->serializedItemCodes);
    }

    public function test_receipt_preserves_zero_identifier(): void
    {
        // Reuse the actual Inventory receipt boundary used before Ownership links received pieces.
        $request = InventoryReceiveRequest::fromValidated(['location_code' => 'SHOP-A', 'quantity' => '1',
            'inventory_item_code' => 'ITEM-A', 'serialized_item_identifiers' => [' 0 ']]);
        $this->assertSame(['0'], $request->serializedItemIdentifiers);
    }

    #[DataProvider('duplicateCodes')]
    public function test_link_rejects_duplicate_codes(array $codes): void
    {
        // Reject duplicates after normalization instead of silently reducing the requested selection.
        $this->expectException(InvalidTenantRequest::class);
        $this->link(['serialized_item_codes' => $codes]);
    }

    #[DataProvider('invalidCodes')]
    public function test_link_rejects_invalid_piece_code(array $codes): void
    {
        // Refuse blank or nonstring references without coercion.
        $this->expectException(InvalidTenantRequest::class);
        $this->link(['serialized_item_codes' => $codes]);
    }

    #[DataProvider('privateFields')]
    public function test_public_link_omits_private_fields(string $field, mixed $value): void
    {
        // Public receipt references cannot carry tenant, payment, source or bypass authority.
        $request = $this->link([$field => $value]);
        $this->assertArrayNotHasKey($field, $request->toArray());
        $this->assertSame('RECEIPT-A', $request->purchaseReceiptLineCode);
    }

    public function test_link_uses_idempotency_key_from_argument(): void
    {
        // Payload fields cannot replace the key supplied from the HTTP header by the controller.
        $request = $this->link(['idempotency_key' => 'payload-key'], 'header-key');
        $this->assertSame('header-key', $request->idempotencyKey);
    }

    public static function privateFields(): array
    {
        // Each payload attempts one authority override; no source eligibility is mocked here.
        return [
            'tenant selection' => ['tenant_id', 999],
            'paid assertion' => ['is_paid', true],
            'internal bypass' => ['bypass', true],
            'trusted context flag' => ['trusted_context', true],
            'nested source' => ['source', ['tenant_id' => 999, 'is_paid' => true]],
            'nested source context' => ['source_context', ['tenant_id' => 999, 'is_paid' => true]],
            'source module' => ['source_module', 'PURCHASING'],
            'source type' => ['source_type', 'PurchaseOrderLine'],
            'source reference' => ['source_code', 'FORGED-LINE'],
        ];
    }

    public static function duplicateCodes(): array
    {
        // Keep both exact and normalization-created collisions visible.
        return [
            'exact duplicate' => [['PIECE-A', 'PIECE-A']],
            'trimmed duplicate' => [[' PIECE-A ', 'PIECE-A']],
            'zero duplicate' => [['0', ' 0 ']],
        ];
    }

    public static function invalidCodes(): array
    {
        // A numeric zero must not be confused with the valid string identifier zero.
        return [
            'empty' => [['']], 'blank' => [['   ']], 'null' => [[null]],
            'integer zero' => [[0]], 'boolean' => [[true]], 'nested array' => [[[]]],
        ];
    }

    private function acquisition(array $changes = []): OwnershipAcquisitionCreate
    {
        // Keep the public/manual value DTO separate from internally resolved acquisition evidence.
        return OwnershipAcquisitionCreate::fromValidated(array_replace([
            'inventory_item_code' => 'ITEM-PUBLIC', 'quantity' => '1.000', 'acquired_at' => '2026-10-21',
            'unit_cost_basis' => '12.3400', 'currency_code' => 'MMK',
        ], $changes));
    }

    private function source(): OwnershipSource
    {
        // Supply typed resolver-output facts without querying, paying, receiving, or acquiring goods.
        return new OwnershipSource(
            tenantId: 1, sourceModule: ' purchasing ', sourceType: ' PurchaseOrderLine ', sourceCode: ' LINE-A ',
            inventoryItemCode: ' ITEM-A ', trackingMode: 'SERIALIZED', quantity: '3', acquiredAt: '2026-10-21',
            unitCostBasis: '12.34', estimatedUnitValue: null, currencyCode: ' mmk ', description: null,
        );
    }

    private function link(array $changes = [], ?string $key = null): OwnershipReceiptLinkRequest
    {
        // Item and lot remain path/service references; the public payload selects only a receipt and pieces.
        return OwnershipReceiptLinkRequest::fromValidated(array_replace([
            'purchase_receipt_line_code' => 'RECEIPT-A', 'quantity' => '1.000', 'update_key' => 0,
        ], $changes), $key);
    }
}
