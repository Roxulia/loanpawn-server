<?php

namespace App\Rules;

use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    public static function strong(): Password
    {
        return Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols();
    }
}
