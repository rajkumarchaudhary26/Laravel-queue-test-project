<?php

return [

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,localhost:8090',
        env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST) : ''
    ))),

    'guard' => ['web'],

    'expiration' => 120,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'token'),

    'middleware' => [
        'verify_csrf_token' => App\Http\Middleware\VerifyCsrfToken::class,
        'ensure_front_cookie' => Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    ],

];
