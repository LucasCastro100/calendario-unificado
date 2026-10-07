<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar OAuth Providers
    |--------------------------------------------------------------------------
    |
    | Credenciais de OAuth dos provedores de calendário. Consumidas apenas
    | por App\Services e App\Http\Controllers\Api\OAuthController — nenhum
    | outro lugar deve ler estas chaves.
    |
    | O redirect_uri aponta para o FRONTEND (Next.js), que repassa o callback
    | ao Laravel. Mantê-lo na mesma origem do Next evita problema de cookie
    | de sessão entre domínios diferentes.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        // 'common' permite contas pessoais e corporativas; use o tenant id
        // para restringir a uma organização específica.
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

];
