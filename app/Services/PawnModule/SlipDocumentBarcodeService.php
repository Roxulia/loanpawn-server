<?php

namespace App\Services\PawnModule;

use Mpdf\Barcode;
use Throwable;

class SlipDocumentBarcodeService
{
    protected const DEFAULT_BARCODE_TYPE = 'C128B';

    protected const FALLBACK_BARCODE_TYPE = 'C128B';

    protected const DEFAULT_BARCODE_HEIGHT_MM = 18.0;

    protected const MIN_BAR_HEIGHT_MM = 12.0;

    protected const MODULE_WIDTH_MM = 0.33;

    protected const QUIET_ZONE_MODULES = 12.0;

    protected const TEXT_HEIGHT_MM = 5.0;

    protected const TEXT_FONT_SIZE_MM = 1.2;

    protected const TEXT_GAP_MM = 2.5;

    protected const CENTER_DIVISOR = 2;

    protected const ROUNDING_PRECISION = 2;

    protected const EMPTY_MODULES = 0.0;

    protected const INITIAL_CURSOR = 0.0;

    protected const NO_TEXT_HEIGHT = 0.0;

    protected const SVG_UNIT = 'mm';

    public function renderSvg(
        string $value,
        float $heightMm = self::DEFAULT_BARCODE_HEIGHT_MM,
        bool $showText = true,
        ?string $type = null
    ): string {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $data = $this->barcodeData($value, $type);
        $modules = (float) ($data['maxw'] ?? self::EMPTY_MODULES);

        if ($modules <= self::EMPTY_MODULES) {
            return '';
        }

        $moduleWidth = $this->moduleWidthMm();
        $quietZoneLeft = ((float) ($data['lightmL'] ?? $this->quietZoneModules())) * $moduleWidth;
        $quietZoneRight = ((float) ($data['lightmR'] ?? $this->quietZoneModules())) * $moduleWidth;
        $barcodeWidth = $modules * $moduleWidth;
        $width = $quietZoneLeft + $barcodeWidth + $quietZoneRight;
        $height = max($this->minimumBarHeightMm(), $heightMm);
        $textHeight = $showText ? self::TEXT_HEIGHT_MM : self::NO_TEXT_HEIGHT;
        $totalHeight = $height + $textHeight;
        $cursor = $quietZoneLeft + self::INITIAL_CURSOR;
        $bars = [];

        foreach ($data['bcode'] ?? [] as $entry) {
            $segmentWidth = ((float) ($entry['w'] ?? self::EMPTY_MODULES)) * $moduleWidth;

            if (($entry['t'] ?? false) === true) {
                $bars[] = sprintf(
                    '<rect x="%s" y="0" width="%s" height="%s" fill="#111827" />',
                    number_format($cursor, self::ROUNDING_PRECISION, '.', ''),
                    number_format($segmentWidth, self::ROUNDING_PRECISION, '.', ''),
                    number_format($height, self::ROUNDING_PRECISION, '.', '')
                );
            }

            $cursor += $segmentWidth;
        }

        $textSvg = '';

        if ($showText) {
            $textSvg = sprintf(
                '<text x="%s" y="%s" text-anchor="middle" font-size="%smm" font-family="monospace" fill="#111827">%s</text>',
                number_format($width / self::CENTER_DIVISOR, self::ROUNDING_PRECISION, '.', ''),
                number_format($height + self::TEXT_GAP_MM + self::TEXT_FONT_SIZE_MM, self::ROUNDING_PRECISION, '.', ''),
                number_format(self::TEXT_FONT_SIZE_MM, self::ROUNDING_PRECISION, '.', ''),
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="%1$s%5$s" height="%2$s%5$s" role="img" aria-label="Slip barcode">%3$s%4$s</svg>',
            number_format($width, self::ROUNDING_PRECISION, '.', ''),
            number_format($totalHeight, self::ROUNDING_PRECISION, '.', ''),
            implode('', $bars),
            $textSvg,
            self::SVG_UNIT
        );
    }

    protected function barcodeData(string $value, ?string $type): array
    {
        $barcode = new Barcode;
        $barcodeType = $this->resolveType($type);
        $quietZoneModules = $this->quietZoneModules();

        try {
            $data = $barcode->getBarcodeArray($value, $barcodeType, '', $quietZoneModules, $quietZoneModules);
        } catch (Throwable) {
            $data = false;
        }

        if ($data !== false) {
            return $data;
        }

        try {
            return $barcode->getBarcodeArray($value, $this->fallbackType(), '', $quietZoneModules, $quietZoneModules) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    protected function resolveType(?string $type): string
    {
        $barcodeType = strtoupper(trim((string) ($type ?? config('slip_document.barcode.default_type', self::DEFAULT_BARCODE_TYPE))));

        return in_array($barcodeType, $this->supportedTypes(), true) ? $barcodeType : $this->fallbackType();
    }

    protected function fallbackType(): string
    {
        $fallbackType = strtoupper(trim((string) config('slip_document.barcode.fallback_type', self::FALLBACK_BARCODE_TYPE)));

        return in_array($fallbackType, $this->supportedTypes(), true) ? $fallbackType : self::FALLBACK_BARCODE_TYPE;
    }

    protected function supportedTypes(): array
    {
        return array_map(
            fn (string $type): string => strtoupper($type),
            config('slip_document.barcode.supported_types', [self::DEFAULT_BARCODE_TYPE, 'C39'])
        );
    }

    protected function moduleWidthMm(): float
    {
        return (float) config('slip_document.barcode.module_width_mm', self::MODULE_WIDTH_MM);
    }

    protected function quietZoneModules(): float
    {
        return (float) config('slip_document.barcode.quiet_zone_modules', self::QUIET_ZONE_MODULES);
    }

    protected function minimumBarHeightMm(): float
    {
        return (float) config('slip_document.barcode.min_height_mm', self::MIN_BAR_HEIGHT_MM);
    }
}
