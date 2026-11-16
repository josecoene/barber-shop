<?php

$origins = env('CORS_ALLOWED_ORIGINS') ?: (env('APP_URL') ?: 'http://localhost');

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
    | BarberFlow: as telas são servidas pelo próprio Laravel (mesma origem) e
    | não precisam de CORS. Só as origens de CORS_ALLOWED_ORIGINS (separadas
    | por vírgula; padrão, o APP_URL) chamam a API pelo navegador, e sem
    | cookies (supports_credentials false): de fora, só com token Bearer.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // A origem é esquema + host + porta, sem barra no fim: "http://localhost:8000/" vira "http://localhost:8000".
    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $origin) => rtrim(trim($origin), '/'),
        explode(',', $origins),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
