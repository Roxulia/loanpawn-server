<?php

namespace App\DataObjects\ResponseObjects;

use App\DataObjects\BaseDataObject;
use Symfony\Component\Mime\Email;

class PlatformUserDetail extends BaseDataObject
{
    /**
     * Create a new class instance.
     */
    public string $email;
    public string $name;
    public function __construct(
        string $email,
        string $name
    )
    {
        $this->name = $name;
        $this->email = $email;
    }
}
