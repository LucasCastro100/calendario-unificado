<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | A API é consumida pelo Next.js. Em desenvolvimento, o Next roda em
    | localhost:3000 e a API em localhost:8000 — origens diferentes, então CORS
    | se aplica. Em produção, defina FRONTEND_URL e CAAS_ORIGIN.
    |
    | IMPORTANTE: `allowed_origins` **jamais** pode ser '*' junto com
    | `supports_credentials => true`. O navegador rejeita silenciosamente essa
    | combinação, e o sintoma aparece como "não envia cookie" sem erro claro.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'oauth/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600,

    /*
    | Necessário para o cookie de sessão do Sanctum funcionar cross-origin.
    */
    'supports_credentials' => true,

];
