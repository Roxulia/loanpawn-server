<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Tenant SPA authentication uses HttpOnly cookies, so CORS cannot use a
    | wildcard origin. Each allowed frontend origin must be listed explicitly.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', env(
            'CORS_ALLOWED_ORIGINS',
            'http://app.loanpawn.1morebit.tech:5173,http://app.loanpawn.1morebit.tech,http://localhost:5173,http://127.0.0.1:5173'
        ))
    )),

    'allowed_origins_patterns' => array_filter(array_map(
        'trim',
        explode(',', env(
            'CORS_ALLOWED_ORIGIN_PATTERNS',
            '#^http://[a-z0-9-]+\.loanpawn\.1morebit\.tech(:[0-9]+)?$#'
        ))
    )),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
