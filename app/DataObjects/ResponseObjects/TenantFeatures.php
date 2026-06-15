<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class TenantFeatures extends BaseDataObject
{
    /**
     * @param array<string, array{code: string, is_active: bool, is_enabled: bool, unlock_in: array{code: string, name: string}|null}> $features
     */
    public function __construct(
        private array $features = [],
    ) {
    }

    /**
     * @return array<string, array{code: string, is_active: bool, is_enabled: bool, unlock_in: array{code: string, name: string}|null}>
     */
    public function toArray(): array
    {
        return $this->features;
    }
}
