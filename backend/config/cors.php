<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Comma-separated list in CORS_ALLOWED_ORIGINS, so the deployed frontend's
     * origin (e.g. https://azizahub.contact-268.workers.dev) is configuration,
     * not a code change. Falls back to the local dev servers, which keeps
     * `ng serve` working with no .env entry at all.
     *
     * Origins must have no trailing slash and must include the scheme, or the
     * browser will not match them and every request fails preflight.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200,http://127.0.0.1:4200'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];