<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Origins are scoped to the known ForgeKin apps. Override per environment
| with a comma-separated CORS_ALLOWED_ORIGINS env var, e.g.:
|
|   CORS_ALLOWED_ORIGINS="https://admin.forgekin.org,https://forgekin.org"
|
| Local dev origins (localhost / 127.0.0.1 on any port) are always allowed
| via the patterns below.
|
*/

$envOrigins = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $envOrigins ?: [
        'https://forgekin.org',
        'https://www.forgekin.org',
        'https://admin.forgekin.org',
    ],

    'allowed_origins_patterns' => [
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer-token auth (not cookie-based), so credentials aren't needed.
    // If you switch to Sanctum SPA cookie auth, set this to true AND ensure
    // allowed_origins lists exact origins (no "*").
    'supports_credentials' => false,

];
