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
    | Can be set via Settings or environment variables
    | Settings take precedence over environment
    */
    'client_id' => fn () => app(\Zynqa\FilamentFreeAgent\Settings\FreeAgentSettings::class)->client_id
        ?? env('FREEAGENT_CLIENT_ID'),

    'client_secret' => fn () => app(\Zynqa\FilamentFreeAgent\Settings\FreeAgentSettings::class)->client_secret
        ?? env('FREEAGENT_CLIENT_SECRET'),

    'redirect_uri' => env('FREEAGENT_REDIRECT_URI', env('APP_URL').'/freeagent/callback'),

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
