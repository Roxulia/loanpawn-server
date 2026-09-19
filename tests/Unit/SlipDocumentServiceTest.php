<?php

namespace Tests\Unit;

use App\Services\PawnModule\SlipDocumentLayoutValidator;
use App\Services\PawnModule\SlipDocumentService;
use Tests\TestCase;

class SlipDocumentServiceTest extends TestCase
{
    public function test_default_header_places_tenant_name_above_barcode(): void
    {
        $layout = app(SlipDocumentLayoutValidator::class)->defaultHeaderLayout();
        $components = $layout['components'];
        $tenantNameIndex = array_search('tenant_name', array_column($components, 'type'), true);
        $barcodeIndex = array_search('barcode', array_column($components, 'type'), true);

        $this->assertSame($barcodeIndex - 1, $tenantNameIndex);
        $this->assertSame('center', $components[$tenantNameIndex]['style']['align']);
        $this->assertSame('bold', $components[$tenantNameIndex]['style']['font_weight']);
        $this->assertEquals(12, $components[$tenantNameIndex]['style']['font_size_pt']);
    }

    public function test_legacy_default_header_renders_escaped_tenant_name_above_barcode(): void
    {
        $layout = json_decode(json_encode($this->legacyDefaultHeaderLayout(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $html = app(SlipDocumentService::class)->renderLayout($layout, 'header', $this->context('Gold & Gems'));

        $tenantNamePosition = strpos($html, 'Gold &amp; Gems');
        $barcodePosition = strpos($html, 'aria-label="Slip barcode"');

        $this->assertNotFalse($tenantNamePosition);
        $this->assertNotFalse($barcodePosition);
        $this->assertLessThan($barcodePosition, $tenantNamePosition);
        $this->assertSame(1, substr_count($html, 'Gold &amp; Gems'));
    }

    public function test_customized_header_does_not_receive_tenant_name_automatically(): void
    {
        $layout = $this->legacyDefaultHeaderLayout();
        $layout['components'][1]['props']['text'] = 'Customized Contract';

        $html = app(SlipDocumentService::class)->renderLayout($layout, 'header', $this->context('Gold & Gems'));

        $this->assertStringNotContainsString('Gold &amp; Gems', $html);
    }

    public function test_current_default_header_does_not_duplicate_tenant_name(): void
    {
        $layout = app(SlipDocumentLayoutValidator::class)->defaultHeaderLayout();

        $html = app(SlipDocumentService::class)->renderLayout($layout, 'header', $this->context('Gold & Gems'));

        $this->assertSame(1, substr_count($html, 'Gold &amp; Gems'));
    }

    protected function legacyDefaultHeaderLayout(): array
    {
        $layout = app(SlipDocumentLayoutValidator::class)->defaultHeaderLayout();
        $layout['components'] = array_values(array_filter(
            $layout['components'],
            fn (array $component): bool => $component['type'] !== 'tenant_name'
        ));

        return $layout;
    }

    protected function context(string $tenantName): array
    {
        return [
            'tenant' => [
                'logoDataUri' => null,
                'name' => $tenantName,
                'phone' => null,
                'address' => null,
            ],
            'slip' => ['slipNo' => 'SLIP-001'],
        ];
    }
}
