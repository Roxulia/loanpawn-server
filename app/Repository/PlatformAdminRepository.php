<?php

namespace App\Repository;

use App\Models\PlatformModule\PlatformAdmin;

class PlatformAdminRepository
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function findByEmail(string $email) : ?PlatformAdmin
    {
        $res = PlatformAdmin::query()->where('email',$email)->first();
        return $res;
    }
}
