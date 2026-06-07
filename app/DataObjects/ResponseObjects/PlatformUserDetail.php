<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;

class PlatformUserDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public string $email;
    public string $name;
    public string $preferLang;

    public function __construct(
        string $email,
        string $name,
        string $preferLang = 'en',
    ) {
        $this->name = $name;
        $this->email = $email;
        $this->preferLang = $preferLang;
    }
}
