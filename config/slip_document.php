<?php

return [
    'fonts' => [
        'preview_stack' => "'Pyidaungsu', 'Myanmar Text', 'Noto Sans Myanmar', sans-serif",
        'mpdf_default' => 'pyidaungsu',
        'mpdf_font_dir' => env('SLIP_DOCUMENT_FONT_DIR', 'C:\\Windows\\Fonts'),
        'mpdf_font_data' => [
            'pyidaungsu' => [
                'R' => 'Pyidaungsu-2.5.1_Regular.ttf',
                'B' => 'Pyidaungsu-2.5.1_Bold.ttf',
            ],
            'myanmar3' => [
                'R' => 'Myanmar3-2018.ttf',
                'B' => 'Myanmar3_Head.ttf',
            ],
        ],
    ],
    'paper_types' => [
        'A4' => [
            'width_mm' => 210,
            'height_mm' => 297,
            'default_orientation' => 'portrait',
            'default_margin_mm' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12],
        ],
        'A5' => [
            'width_mm' => 148,
            'height_mm' => 210,
            'default_orientation' => 'portrait',
            'default_margin_mm' => ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10],
        ],
        'Receipt80' => [
            'width_mm' => 80,
            'height_mm' => 210,
            'default_orientation' => 'portrait',
            'default_margin_mm' => ['top' => 6, 'right' => 4, 'bottom' => 6, 'left' => 4],
        ],
        'Receipt58' => [
            'width_mm' => 58,
            'height_mm' => 180,
            'default_orientation' => 'portrait',
            'default_margin_mm' => ['top' => 5, 'right' => 3, 'bottom' => 5, 'left' => 3],
        ],
    ],
    'sizing' => [
        'orientations' => ['portrait', 'landscape'],
        'margin_mm' => ['min' => 0, 'max' => 40],
        'padding_mm' => ['min' => 0, 'max' => 20],
        'font_size_pt' => ['min' => 6, 'max' => 36],
        'width_percent' => ['min' => 10, 'max' => 100],
        'height_mm' => ['min' => 1, 'max' => 120],
        'line_height' => ['min' => 1.0, 'max' => 2.5],
        'border_width_mm' => ['min' => 0, 'max' => 3],
        'logo_width_mm' => ['min' => 10, 'max' => 80],
        'spacer_height_mm' => ['min' => 1, 'max' => 40],
    ],
    'layout' => [
        'max_depth' => 3,
        'max_components' => 40,
        'zones' => ['header', 'footer'],
        'allowed_components' => [
            'text' => ['zones' => ['header', 'footer'], 'props' => ['text','x','y','font_size_pt'], 'supports_children' => false],
            'tenant_logo' => ['zones' => ['header', 'footer'], 'props' => ['width_mm', 'height_mm', 'x', 'y'], 'supports_children' => false],
            'tenant_name' => ['zones' => ['header', 'footer'], 'props' => ['x', 'y'], 'supports_children' => false],
            'tenant_phone' => ['zones' => ['header', 'footer'], 'props' => ['x', 'y'], 'supports_children' => false],
            'tenant_address' => ['zones' => ['header', 'footer'], 'props' => ['x', 'y'], 'supports_children' => false],
            'slip_number' => ['zones' => ['header', 'footer'], 'props' => ['label', 'x', 'y'], 'supports_children' => false],
            'barcode' => ['zones' => ['header', 'footer'], 'props' => ['height_mm', 'show_text', 'x', 'y'], 'supports_children' => false],
            'divider' => ['zones' => ['header', 'footer'], 'props' => ['border_width_mm','y'], 'supports_children' => false],
            'spacer' => ['zones' => ['header', 'footer'], 'props' => ['height_mm'], 'supports_children' => false],
            'row' => ['zones' => ['header', 'footer'], 'props' => ['gap_mm', 'justify', 'x', 'y'], 'supports_children' => true],
        ],
    ],
];
