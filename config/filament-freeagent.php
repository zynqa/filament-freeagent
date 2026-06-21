<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | FreeAgent Environment
    |--------------------------------------------------------------------------
    | Options: 'production' or 'sandbox'
    | Determines which FreeAgent API environment to use
    */
    'environment' => env('FREEAGENT_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | API URLs
    |--------------------------------------------------------------------------
    | Automatically selects production or sandbox URLs based on environment
    */
    'api_url' => env('FREEAGENT_ENV', 'production') === 'production'
        ? 'https://api.freeagent.com/v2'
        : 'https://api.sandbox.freeagent.com/v2',

    'authorize_url' => env('FREEAGENT_ENV', 'production') === 'production'
        ? 'https://api.freeagent.com/v2/approve_app'
        : 'https://api.sandbox.freeagent.com/v2/approve_app',

    'token_url' => env('FREEAGENT_ENV', 'production') === 'production'
        ? 'https://api.freeagent.com/v2/token_endpoint'
        : 'https://api.sandbox.freeagent.com/v2/token_endpoint',

    /*
    |--------------------------------------------------------------------------
    | OAuth Credentials
    |--------------------------------------------------------------------------
    | Resolved from environment by default. The host application may override
    | these at runtime from its own settings store (see the service provider's
    | loadSettingsIntoConfig()), which takes precedence over these env values.
    */
    'client_id' => env('FREEAGENT_CLIENT_ID'),

    'client_secret' => env('FREEAGENT_CLIENT_SECRET'),

    'redirect_uri' => env('FREEAGENT_REDIRECT_URI', env('APP_URL').'/freeagent/callback'),

    /*
    |--------------------------------------------------------------------------
    | Panel Redirect Routes
    |--------------------------------------------------------------------------
    | The OAuth controller redirects here after connect/disconnect/error.
    | Defaults assume a Filament panel with id "app"; override these for a
    | panel registered under a different id (e.g. "auth" => filament.auth.*).
    */
    'routes' => [
        'login' => env('FREEAGENT_LOGIN_ROUTE', 'filament.app.auth.login'),
        'dashboard' => env('FREEAGENT_DASHBOARD_ROUTE', 'filament.app.pages.dashboard'),
        'settings' => env('FREEAGENT_SETTINGS_ROUTE', 'filament.app.pages.manage-general-settings'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    | TTL values in seconds
    */
    'cache' => [
        'invoices_ttl' => env('FREEAGENT_CACHE_INVOICES', 1800), // 30 minutes
        'contacts_ttl' => env('FREEAGENT_CACHE_CONTACTS', 3600), // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    | FreeAgent API rate limits per OAuth application
    */
    'rate_limit' => [
        'per_minute' => 120,
        'per_hour' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    | Default pagination settings for API requests
    */
    'pagination' => [
        'default_per_page' => 25,
        'max_per_page' => 100,
    ],
];
