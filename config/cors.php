<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'uploads/*', 'storage/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:8100,http://localhost:5173,capacitor://localhost,ionic://localhost,https://lauapp.es,https://www.lauapp.es'
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'Origin',
        'X-Requested-With',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
