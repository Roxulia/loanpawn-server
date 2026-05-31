<?php

namespace App\Services\PawnModule;

use App\Exceptions\InvalidTenantRequest;

class SlipDocumentLayoutValidator
{
    protected const DEFAULT_LAYOUT_VERSION = 1;
    protected const DEFAULT_MAX_COMPONENTS = 40;
    protected const DEFAULT_MAX_DEPTH = 3;
    protected const DEFAULT_ROW_GAP_MM = 3;
    protected const DEFAULT_LOGO_WIDTH_MM = 22;
    protected const DEFAULT_HEADER_SIDE_WIDTH_PERCENT = 24;
    protected const DEFAULT_HEADER_CENTER_WIDTH_PERCENT = 52;
    protected const DEFAULT_BARCODE_HEIGHT_MM = 10;
    protected const DEFAULT_DIVIDER_WIDTH_MM = 0.4;
    protected const DEFAULT_SECTION_MARGIN_MM = 2;
    protected const DEFAULT_FOOTER_FONT_SIZE_PT = 9;
    protected const DEFAULT_COMPONENT_LOGO_WIDTH_MM = 24;
    protected const DEFAULT_SPACER_HEIGHT_MM = 4;
    protected const DEFAULT_STYLE_FONT_SIZE_PT = 10;
    protected const DEFAULT_STYLE_WIDTH_PERCENT = 100;
    protected const DEFAULT_STYLE_LINE_HEIGHT = 1.4;
    protected const DEFAULT_SIZE_MIN = 0;
    protected const DEFAULT_SIZE_MAX = 9999;
    protected const ROUNDING_PRECISION = 2;

    public function normalizeLayout(?array $layout, string $zone): array
    {
        $config = config('slip_document.layout');
        $components = $layout['components'] ?? [];

        if (! is_array($components)) {
            throw new InvalidTenantRequest('Slip document layout components must be an array.');
        }

        if (count($components) > (int) ($config['max_components'] ?? self::DEFAULT_MAX_COMPONENTS)) {
            throw new InvalidTenantRequest('Slip document layout exceeds the maximum allowed component count.');
        }

        return [
            'version' => self::DEFAULT_LAYOUT_VERSION,
            'components' => array_map(
                fn (array $component): array => $this->normalizeComponent($component, $zone, self::DEFAULT_LAYOUT_VERSION),
                $components
            ),
        ];
    }

    public function defaultHeaderLayout(): array
    {
        return $this->normalizeLayout([
            'components' => [

                [
                    'type' => 'tenant_logo',
                    'props' => ['width_mm' => self::DEFAULT_LOGO_WIDTH_MM],
                    'style' => ['width_percent' => self::DEFAULT_HEADER_SIDE_WIDTH_PERCENT],
                ],
                [
                    'type' => 'text',
                    'props' => ['text' => 'Pawn Loan Contract Slip'],
                    'style' => [
                        'font_size_pt' => 14,
                        'font_weight' => 'bold',
                        'align' => 'center',
                    ],
                ],
                [
                    'type' => 'barcode',
                    'props' => ['height_mm' => self::DEFAULT_BARCODE_HEIGHT_MM, 'show_text' => true],
                    'style' => ['align' => 'center'],
                ],
                [
                    'type' => 'divider',
                    'props' => ['border_width_mm' => self::DEFAULT_DIVIDER_WIDTH_MM],
                    'style' => ['margin_top_mm' => self::DEFAULT_SECTION_MARGIN_MM, 'margin_bottom_mm' => self::DEFAULT_SECTION_MARGIN_MM],
                ],
            ],
        ], 'header');
    }

    public function defaultFooterLayout(): array
    {
        return $this->normalizeLayout([
            'components' => [
                [
                    'type' => 'divider',
                    'props' => ['border_width_mm' => self::DEFAULT_DIVIDER_WIDTH_MM],
                    'style' => ['margin_top_mm' => self::DEFAULT_SECTION_MARGIN_MM, 'margin_bottom_mm' => self::DEFAULT_SECTION_MARGIN_MM],
                ],
                [
                    'type' => 'tenant_address',
                    'style' => ['font_size_pt' => self::DEFAULT_FOOTER_FONT_SIZE_PT, 'align' => 'center'],
                ],
                [
                    'type' => 'tenant_phone',
                    'style' => ['font_size_pt' => self::DEFAULT_FOOTER_FONT_SIZE_PT, 'align' => 'center'],
                ],
            ],
        ], 'footer');
    }

    protected function normalizeComponent(array $component, string $zone, int $depth): array
    {
        $config = config('slip_document.layout');
        $allowed = $config['allowed_components'] ?? [];
        $maxDepth = (int) ($config['max_depth'] ?? self::DEFAULT_MAX_DEPTH);
        $type = (string) ($component['type'] ?? '');

        if ($type === '' || ! isset($allowed[$type])) {
            throw new InvalidTenantRequest('Slip document layout contains an unsupported component.');
        }

        if (! in_array($zone, $allowed[$type]['zones'], true)) {
            throw new InvalidTenantRequest('Slip document component is not allowed in this layout zone.');
        }

        if ($depth > $maxDepth) {
            throw new InvalidTenantRequest('Slip document layout nesting exceeds the maximum allowed depth.');
        }

        $normalized = [
            'type' => $type,
            'props' => $this->normalizeProps($type, is_array($component['props'] ?? null) ? $component['props'] : []),
            'style' => $this->normalizeStyle(is_array($component['style'] ?? null) ? $component['style'] : []),
        ];

        if (($allowed[$type]['supports_children'] ?? false) === true) {
            $children = $component['children'] ?? [];

            if (! is_array($children)) {
                throw new InvalidTenantRequest('Slip document component children must be an array.');
            }

            $normalized['children'] = array_map(
                fn (array $child): array => $this->normalizeComponent($child, $zone, $depth + 1),
                $children
            );
        }

        return $normalized;
    }

    protected function normalizeProps(string $type, array $props): array
    {
        return match ($type) {
            'text' => [
                'text' => trim((string) ($props['text'] ?? '')),
            ],
            'tenant_logo' => [
                'width_mm' => $this->normalizeSize($props['width_mm'] ?? self::DEFAULT_COMPONENT_LOGO_WIDTH_MM, 'logo_width_mm'),
            ],
            'slip_number' => [
                'label' => trim((string) ($props['label'] ?? 'Slip No')),
            ],
            'barcode' => [
                'height_mm' => $this->normalizeSize($props['height_mm'] ?? self::DEFAULT_BARCODE_HEIGHT_MM, 'height_mm'),
                'show_text' => filter_var($props['show_text'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            ],
            'divider' => [
                'border_width_mm' => $this->normalizeSize($props['border_width_mm'] ?? self::DEFAULT_DIVIDER_WIDTH_MM, 'border_width_mm'),
            ],
            'spacer' => [
                'height_mm' => $this->normalizeSize($props['height_mm'] ?? self::DEFAULT_SPACER_HEIGHT_MM, 'spacer_height_mm'),
            ],
            'row' => [
                'gap_mm' => $this->normalizeSize($props['gap_mm'] ?? self::DEFAULT_SECTION_MARGIN_MM, 'padding_mm'),
                'justify' => $this->normalizeEnum((string) ($props['justify'] ?? 'start'), ['start', 'center', 'end', 'between']),
            ],
            default => [],
        };
    }

    protected function normalizeStyle(array $style): array
    {
        return [
            'align' => $this->normalizeEnum((string) ($style['align'] ?? 'left'), ['left', 'center', 'right']),
            'font_size_pt' => $this->normalizeSize($style['font_size_pt'] ?? self::DEFAULT_STYLE_FONT_SIZE_PT, 'font_size_pt'),
            'font_weight' => $this->normalizeEnum((string) ($style['font_weight'] ?? 'normal'), ['normal', 'bold']),
            'width_percent' => $this->normalizeSize($style['width_percent'] ?? self::DEFAULT_STYLE_WIDTH_PERCENT, 'width_percent'),
            'line_height' => $this->normalizeLineHeight($style['line_height'] ?? self::DEFAULT_STYLE_LINE_HEIGHT),
            'margin_top_mm' => $this->normalizeSize($style['margin_top_mm'] ?? 0, 'margin_mm'),
            'margin_right_mm' => $this->normalizeSize($style['margin_right_mm'] ?? 0, 'margin_mm'),
            'margin_bottom_mm' => $this->normalizeSize($style['margin_bottom_mm'] ?? 0, 'margin_mm'),
            'margin_left_mm' => $this->normalizeSize($style['margin_left_mm'] ?? 0, 'margin_mm'),
            'padding_top_mm' => $this->normalizeSize($style['padding_top_mm'] ?? 0, 'padding_mm'),
            'padding_right_mm' => $this->normalizeSize($style['padding_right_mm'] ?? 0, 'padding_mm'),
            'padding_bottom_mm' => $this->normalizeSize($style['padding_bottom_mm'] ?? 0, 'padding_mm'),
            'padding_left_mm' => $this->normalizeSize($style['padding_left_mm'] ?? 0, 'padding_mm'),
        ];
    }

    protected function normalizeSize(mixed $value, string $configKey): float|int
    {
        if (! is_numeric($value)) {
            throw new InvalidTenantRequest('Slip document sizing values must be numeric.');
        }

        $number = (float) $value;
        $range = config("slip_document.sizing.{$configKey}");
        $min = (float) ($range['min'] ?? self::DEFAULT_SIZE_MIN);
        $max = (float) ($range['max'] ?? self::DEFAULT_SIZE_MAX);

        if ($number < $min || $number > $max) {
            throw new InvalidTenantRequest('Slip document sizing value is outside the allowed range.');
        }

        return $configKey === 'width_percent' ? (int) round($number) : round($number, self::ROUNDING_PRECISION);
    }

    protected function normalizeLineHeight(mixed $value): float
    {
        if (! is_numeric($value)) {
            throw new InvalidTenantRequest('Slip document line height must be numeric.');
        }

        $number = (float) $value;
        $range = config('slip_document.sizing.line_height');

        if ($number < $range['min'] || $number > $range['max']) {
            throw new InvalidTenantRequest('Slip document line height is outside the allowed range.');
        }

        return round($number, self::ROUNDING_PRECISION);
    }

    protected function normalizeEnum(string $value, array $allowed): string
    {
        $normalized = strtolower(trim($value));

        if (! in_array($normalized, $allowed, true)) {
            throw new InvalidTenantRequest('Slip document layout contains an invalid option.');
        }

        return $normalized;
    }
}
