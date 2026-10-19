<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| The API (routes/api.php) is called by the connector programs of units with an API key, never by a browser of another
| site, and the application itself is served from its own origin. So no foreign origin is allowed: a browser on another
| site cannot read answers of this application. If a browser client of another origin is ever needed, list its exact
| origin in CORS_ALLOWED_ORIGINS (comma separated) instead of opening everything.
|
*/

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Api-Key', 'Accept'],

    'exposed_headers' => [],

    'max_age' => 600,

    // cookies and sessions are never sent cross-origin
    'supports_credentials' => false,
];
