<?php

namespace Tests\Unit;

use App\Services\PawnModule\SlipDocumentBarcodeService;
use Tests\TestCase;

class SlipDocumentBarcodeServiceTest extends TestCase
{
    public function test_it_renders_scanner_safe_code128_barcode_by_default(): void
    {
        config()->set('slip_document.barcode.default_type', 'C128B');
        config()->set('slip_document.barcode.module_width_mm', 0.26);
        config()->set('slip_document.barcode.quiet_zone_modules', 8);

        $service = app(SlipDocumentBarcodeService::class);

        $svg = $service->renderSvg('LS202607HEH07EB001');
        preg_match('/width="([0-9.]+)mm"/', $svg, $matches);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('width="', $svg);
        $this->assertStringContainsString('mm"', $svg);
        $this->assertStringContainsString('LS202607HEH07EB001', $svg);
        $this->assertMatchesRegularExpression('/<rect x="2\.08"/', $svg);
        $this->assertLessThanOrEqual(66.0, (float) $matches[1]);
        $this->assertSame($svg, $service->renderSvg('LS202607HEH07EB001', 18.0, true, 'C128B'));
    }

    public function test_it_supports_code39_for_compatibility(): void
    {
        $service = app(SlipDocumentBarcodeService::class);

        $code128Svg = $service->renderSvg('LS202607HEH07EB001', 18.0, true, 'C128B');
        $code39Svg = $service->renderSvg('LS202607HEH07EB001', 18.0, true, 'C39');

        $this->assertStringStartsWith('<svg', $code39Svg);
        $this->assertNotSame($code128Svg, $code39Svg);
    }

    public function test_it_preserves_minimum_height_and_can_hide_text(): void
    {
        $service = app(SlipDocumentBarcodeService::class);

        $svg = $service->renderSvg('LS202607HEH07EB001', 8.0, false);

        $this->assertStringContainsString('height="12.00mm"', $svg);
        $this->assertStringNotContainsString('<text', $svg);
    }

    public function test_it_falls_back_to_supported_type_for_invalid_type(): void
    {
        $service = app(SlipDocumentBarcodeService::class);

        $fallbackSvg = $service->renderSvg('LS202607HEH07EB001', 18.0, true, 'C128B');
        $invalidTypeSvg = $service->renderSvg('LS202607HEH07EB001', 18.0, true, 'UNSUPPORTED');

        $this->assertSame($fallbackSvg, $invalidTypeSvg);
    }
}
