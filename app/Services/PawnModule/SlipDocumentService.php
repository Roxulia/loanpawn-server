<?php

namespace App\Services\PawnModule;

use App\DataObjects\RequestObjects\SlipDocumentRenderRequest;
use App\DataObjects\ResponseObjects\SlipDocumentLayoutConfig;
use App\Exceptions\InvalidTenantRequest;
use Illuminate\Support\Facades\Storage;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

class SlipDocumentService
{
    protected const EMPTY_BINARY_RESPONSE = '';

    protected const STORAGE_DISK = 'public';

    protected const DEFAULT_IMAGE_MIME = 'image/png';

    protected const MPDF_TEMP_DIRECTORY = 'app/mpdf-temp';

    protected const DIRECTORY_PERMISSIONS = 0777;

    protected const DIRECTORY_RECURSIVE = true;

    protected const ORIENTATION_LANDSCAPE = 'landscape';

    protected const ORIENTATION_INDEX = 0;

    protected const ORIENTATION_LENGTH = 1;

    protected const EMPTY_WIDTH = 100;

    protected const ZERO_MARGIN = 0;

    protected const DEFAULT_LOGO_WIDTH_MM = 24;

    protected const DEFAULT_BARCODE_HEIGHT_MM = 10;

    protected const DEFAULT_DIVIDER_WIDTH_MM = 0.4;

    protected const DEFAULT_SPACER_HEIGHT_MM = 4;

    protected const DEFAULT_FONT_SIZE_PT = 10;

    protected const DEFAULT_LINE_HEIGHT = 1.4;

    protected const TABLE_WIDTH_PERCENT = 100;

    public function __construct(
        private SlipDocumentBarcodeService $barcodeService,
        private SlipDocumentLayoutValidator $layoutValidator,
    ) {}

    public function getLayoutConfig(): SlipDocumentLayoutConfig
    {
        return SlipDocumentLayoutConfig::fromConfig(config('slip_document'));
    }

    public function resolvePaperSettings(SlipDocumentRenderRequest $request): array
    {
        $paperTypes = config('slip_document.paper_types');
        $paperType = trim($request->paperType);

        if (! isset($paperTypes[$paperType])) {
            throw new InvalidTenantRequest('Unsupported slip document paper type.');
        }

        $orientation = strtolower(trim($request->orientation));

        if (! in_array($orientation, config('slip_document.sizing.orientations'), true)) {
            throw new InvalidTenantRequest('Unsupported slip document orientation.');
        }

        $preset = $paperTypes[$paperType];
        $width = (float) $preset['width_mm'];
        $height = (float) $preset['height_mm'];

        if ($orientation === self::ORIENTATION_LANDSCAPE) {
            [$width, $height] = [$height, $width];
        }

        return [
            'paperType' => $paperType,
            'orientation' => $orientation,
            'widthMm' => $width,
            'heightMm' => $height,
            'marginMm' => $preset['default_margin_mm'],
        ];
    }

    public function imageDataUri(?string $path): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $disk = Storage::disk(self::STORAGE_DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $fullPath = $disk->path($path);
        $mime = mime_content_type($fullPath) ?: self::DEFAULT_IMAGE_MIME;
        $contents = file_get_contents($fullPath);

        if ($contents === false) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    public function renderLayout(array $layout, string $zone, array $context): string
    {
        $components = $this->componentsForRendering($layout, $zone);

        return implode('', array_map(
            fn (array $component): string => $this->renderComponent($component, $zone, $context),
            $components
        ));
    }

    public function buildMpdf(array $paper): Mpdf
    {
        $config = (new ConfigVariables)->getDefaults();
        $fontConfig = (new FontVariables)->getDefaults();
        $fontDir = config('slip_document.fonts.mpdf_font_dir');
        $fontData = config('slip_document.fonts.mpdf_font_data');
        $tempDir = storage_path(self::MPDF_TEMP_DIRECTORY);

        if (! is_dir($tempDir)) {
            mkdir($tempDir, self::DIRECTORY_PERMISSIONS, self::DIRECTORY_RECURSIVE);
        }

        return new Mpdf([
            'tempDir' => $tempDir,
            'format' => [$paper['widthMm'], $paper['heightMm']],
            'orientation' => strtoupper(substr($paper['orientation'], self::ORIENTATION_INDEX, self::ORIENTATION_LENGTH)),
            'margin_top' => $paper['marginMm']['top'],
            'margin_right' => $paper['marginMm']['right'],
            'margin_bottom' => $paper['marginMm']['bottom'],
            'margin_left' => $paper['marginMm']['left'],
            'fontDir' => array_merge($config['fontDir'], [$fontDir]),
            'fontdata' => $fontData + $fontConfig['fontdata'],
            'default_font' => config('slip_document.fonts.mpdf_default'),
        ]);
    }

    protected function renderComponent(array $component, string $zone, array $context): string
    {
        $props = $component['props'] ?? [];
        $style = $this->styleToString($component['style'] ?? []).$this->positionStyleToString($props);

        return match ($component['type']) {
            'text' => '<div style="'.$style.'">'.$this->escape($props['text'] ?? self::EMPTY_BINARY_RESPONSE).'</div>',
            'tenant_logo' => $context['tenant']['logoDataUri'] === null
                ? ''
                : '<div style="'.$style.'"><img src="'.$context['tenant']['logoDataUri'].'" alt="Tenant Logo" style="width:'.(float) ($props['width_mm'] ?? self::DEFAULT_LOGO_WIDTH_MM).'mm; height:auto;" /></div>',
            'tenant_name' => '<div style="'.$style.'">'.$this->escape($context['tenant']['name'] ?? self::EMPTY_BINARY_RESPONSE).'</div>',
            'tenant_phone' => '<div style="'.$style.'">'.$this->escape($context['tenant']['phone'] ?? self::EMPTY_BINARY_RESPONSE).'</div>',
            'tenant_address' => '<div style="'.$style.'">'.$this->escape($context['tenant']['address'] ?? self::EMPTY_BINARY_RESPONSE).'</div>',
            'slip_number' => '<div style="'.$style.'">'.$this->escape(($props['label'] ?? 'Slip No').': '.($context['slip']['slipNo'] ?? '')).'</div>',
            'barcode' => '<div style="'.$style.'">'.$this->barcodeService->renderSvg(
                $context['slip']['slipNo'] ?? self::EMPTY_BINARY_RESPONSE,
                (float) ($props['height_mm'] ?? self::DEFAULT_BARCODE_HEIGHT_MM),
                (bool) ($props['show_text'] ?? true),
                $props['type'] ?? null
            ).'</div>',
            'divider' => '<div style="'.$style.' border-top: '.(float) ($props['border_width_mm'] ?? self::DEFAULT_DIVIDER_WIDTH_MM).'mm solid #111827;"></div>',
            'spacer' => '<div style="'.$style.' height: '.(float) ($props['height_mm'] ?? self::DEFAULT_SPACER_HEIGHT_MM).'mm;"></div>',
            'row' => $this->renderRow($component, $context),
            default => self::EMPTY_BINARY_RESPONSE,
        };
    }

    protected function renderRow(array $component, array $context): string
    {
        $style = $this->styleToString($component['style'] ?? []).$this->positionStyleToString($component['props'] ?? []);
        $children = array_map(function (array $child) use ($context): string {
            $width = (int) (($child['style']['width_percent'] ?? self::EMPTY_WIDTH));

            return '<td style="width: '.$width.'%; vertical-align: top; border: none; padding: 0;">'.$this->renderComponent($child, 'row', $context).'</td>';
        }, $component['children'] ?? []);

        return '<table style="'.$style.' width: '.self::TABLE_WIDTH_PERCENT.'%; border-collapse: collapse;"><tr>'.implode('', $children).'</tr></table>';
    }

    protected function componentsForRendering(array $layout, string $zone): array
    {
        $components = $layout['components'] ?? [];

        if ($zone !== 'header') {
            return $components;
        }

        $defaultHeaderLayout = $this->layoutValidator->defaultHeaderLayout();
        $tenantNameComponent = null;
        $legacyDefaultComponents = [];

        foreach ($defaultHeaderLayout['components'] as $component) {
            if ($component['type'] === 'tenant_name') {
                $tenantNameComponent = $component;

                continue;
            }

            $legacyDefaultComponents[] = $component;
        }

        $legacyDefaultLayout = $defaultHeaderLayout;
        $legacyDefaultLayout['components'] = $legacyDefaultComponents;

        if ($tenantNameComponent === null || $layout != $legacyDefaultLayout) {
            return $components;
        }

        foreach ($components as $index => $component) {
            if ($component['type'] === 'barcode') {
                array_splice($components, $index, 0, [$tenantNameComponent]);

                break;
            }
        }

        return $components;
    }

    protected function styleToString(array $style): string
    {
        return sprintf(
            'text-align:%s; font-size:%spt; font-weight:%s; line-height:%s; width:%s%%; margin:%smm %smm %smm %smm; padding:%smm %smm %smm %smm;',
            $style['align'] ?? 'left',
            $style['font_size_pt'] ?? self::DEFAULT_FONT_SIZE_PT,
            $style['font_weight'] ?? 'normal',
            $style['line_height'] ?? self::DEFAULT_LINE_HEIGHT,
            $style['width_percent'] ?? self::EMPTY_WIDTH,
            $style['margin_top_mm'] ?? self::ZERO_MARGIN,
            $style['margin_right_mm'] ?? self::ZERO_MARGIN,
            $style['margin_bottom_mm'] ?? self::ZERO_MARGIN,
            $style['margin_left_mm'] ?? self::ZERO_MARGIN,
            $style['padding_top_mm'] ?? self::ZERO_MARGIN,
            $style['padding_right_mm'] ?? self::ZERO_MARGIN,
            $style['padding_bottom_mm'] ?? self::ZERO_MARGIN,
            $style['padding_left_mm'] ?? self::ZERO_MARGIN,
        );
    }

    protected function positionStyleToString(array $props): string
    {
        if (! array_key_exists('x', $props) && ! array_key_exists('y', $props)) {
            return self::EMPTY_BINARY_RESPONSE;
        }

        return sprintf(
            ' position:absolute; left:%smm; top:%smm;',
            (float) ($props['x'] ?? self::ZERO_MARGIN),
            (float) ($props['y'] ?? self::ZERO_MARGIN),
        );
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
