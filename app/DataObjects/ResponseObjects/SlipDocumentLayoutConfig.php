<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class SlipDocumentLayoutConfig extends BaseDataObject
{
    public array $paperTypes;
    public array $sizing;
    public array $layout;
    public array $fonts;

    public static function fromConfig(array $config): self
    {
        $detail = new self();
        $detail->paperTypes = $config['paper_types'] ?? [];
        $detail->sizing = $config['sizing'] ?? [];
        $detail->layout = $config['layout'] ?? [];
        $detail->fonts = [
            'previewStack' => $config['fonts']['preview_stack'] ?? null,
            'defaultFamily' => $config['fonts']['mpdf_default'] ?? null,
        ];

        return $detail;
    }
}
