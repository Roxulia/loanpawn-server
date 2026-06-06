<?php

namespace App\Utility;

class Messages
{

    public function responseMessage(
        MessageCode $code,
        array $params = []
    ): string {
        $locale = app()->getLocale();

        $key = 'app.' . $code->value;

        $translated = trans($key, $params, $locale);

        return $translated === $key
            ? $code->value
            : $translated;
    }
}
