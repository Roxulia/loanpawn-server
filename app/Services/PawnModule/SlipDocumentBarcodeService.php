<?php

namespace App\Services\PawnModule;

use Mpdf\Barcode;

class SlipDocumentBarcodeService
{
    protected const DEFAULT_BARCODE_HEIGHT_MM = 10.0;
    protected const MODULE_WIDTH = 0.6;
    protected const MIN_BAR_HEIGHT = 4.0;
    protected const HEIGHT_SCALE = 3.2;
    protected const TEXT_HEIGHT = 14.0;
    protected const TEXT_FONT_SIZE = 12;
    protected const CENTER_DIVISOR = 2;
    protected const ROUNDING_PRECISION = 2;
    protected const EMPTY_MODULES = 0.0;
    protected const INITIAL_CURSOR = 0.0;
    protected const NO_TEXT_HEIGHT = 0.0;

    public function renderSvg(string $value, float $heightMm = self::DEFAULT_BARCODE_HEIGHT_MM, bool $showText = true): string
    {
        $barcode = new Barcode();
        $data = $barcode->getBarcodeArray($value, 'C39');
        $modules = (float) ($data['maxw'] ?? self::EMPTY_MODULES);

        if ($modules <= self::EMPTY_MODULES) {
            return '';
        }

        $width = $modules * self::MODULE_WIDTH;
        $height = max(self::MIN_BAR_HEIGHT, $heightMm * self::HEIGHT_SCALE);
        $textHeight = $showText ? self::TEXT_HEIGHT : self::NO_TEXT_HEIGHT;
        $totalHeight = $height + $textHeight;
        $cursor = self::INITIAL_CURSOR;
        $bars = [];

        foreach ($data['bcode'] ?? [] as $entry) {
            $segmentWidth = ((float) ($entry['w'] ?? self::EMPTY_MODULES)) * self::MODULE_WIDTH;

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
                    '<text x="%s" y="%s" text-anchor="middle" font-size="12" font-family="monospace" fill="#111827">%s</text>',
                number_format($width / self::CENTER_DIVISOR, self::ROUNDING_PRECISION, '.', ''),
                number_format($height + self::TEXT_FONT_SIZE, self::ROUNDING_PRECISION, '.', ''),
                htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$s %2$s" width="%1$s" height="%2$s" role="img" aria-label="Slip barcode">%3$s%4$s</svg>',
            number_format($width, self::ROUNDING_PRECISION, '.', ''),
            number_format($totalHeight, self::ROUNDING_PRECISION, '.', ''),
            implode('', $bars),
            $textSvg
        );
    }
}
