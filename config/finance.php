<?php

return [
    'default_currency' => 'MMK',

    'currencies' => [
        ['code' => 'MMK', 'name' => 'Myanmar Kyat', 'symbol' => 'Ks', 'decimal_precision' => 0, 'rounding_mode' => 'HALF_UP'],
        ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_precision' => 2, 'rounding_mode' => 'HALF_UP'],
        ['code' => 'JPY', 'name' => 'Japanese Yen', 'symbol' => '¥', 'decimal_precision' => 0, 'rounding_mode' => 'HALF_UP'],
    ],

    'exchange_pairs' => [
        ['base' => 'USD', 'quote' => 'MMK'],
        ['base' => 'JPY', 'quote' => 'MMK'],
    ],
];
