<?php

namespace App\DataObjects\RequestObjects;

use App\DataObjects\BaseDataObject;

class SlipDocumentRenderRequest extends BaseDataObject
{
    public function __construct(
        public string $slipNo,
        public string $paperType,
        public string $orientation = 'portrait',
    ) {
    }
}
