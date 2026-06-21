<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Zynqa\FilamentFreeAgent\Services\FreeAgentOAuthService;
use Zynqa\FilamentFreeAgent\Services\FreeAgentService;

class FilamentFreeAgentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('filament-freeagent')
            ->hasConfigFile()
            ->hasMigrations([
                'add_freeagent_contact_id_to_users_table',
                'create_freeagent_oauth_tokens_table',
                'create_freeagent_contacts_table',
                'create_freeagent_invoices_table',
                'create_freeagent_projects_table',
                'add_project_fields_to_freeagent_invoices_table',
            ])
            ->hasRoute('web');
    }

    public function packageBooted(): void
    {
        // Load settings from database and override config
        $this->loadSettingsIntoConfig();

        // Register singleton services
        $this->app->singleton(FreeAgentOAuthService::class, function ($app) {
            return new FreeAgentOAuthService;
        });

        $this->app->singleton(FreeAgentService::class, function ($app) {
            return new FreeAgentService(
                $app->make(FreeAgentOAuthService::class)
            );
        });

        $this->app->singleton(\Zynqa\FilamentFreeAgent\Services\FreeAgentCacheService::class, function ($app) {
            return new \Zynqa\FilamentFreeAgent\Services\FreeAgentCacheService(
                $app->make(FreeAgentService::class)
            );
        });
    }

    /**
     * Load FreeAgent settings from database into config at runtime
     */
    protected function loadSettingsIntoConfig(): void
    {
        try {
            if (! class_exists(\App\Settings\GeneralSettings::class)) {
                return;
            }

            $settings = app(\App\Settings\GeneralSettings::class);

            // Read a property only if the host's settings class actually defines
            // it — different host apps expose different FreeAgent settings.
            $get = function (string $property) use ($settings) {
                return property_exists($settings, $property) ? $settings->{$property} : null;
            };

            // Host's master enable toggle (used to gate the package's UI).
            $enabled = $get('freeagent_enabled');
            if ($enabled !== null) {
                config(['filament-freeagent.enabled' => (bool) $enabled]);
            }

            if ($clientId = $get('freeagent_client_id')) {
                config(['filament-freeagent.client_id' => $clientId]);
            }

            if ($clientSecret = $get('freeagent_client_secret')) {
                config(['filament-freeagent.client_secret' => $clientSecret]);
            }

            if ($redirectUri = $get('freeagent_redirect_uri')) {
                config(['filament-freeagent.redirect_uri' => $redirectUri]);
            }

            // Preferred: a simple 'sandbox' | 'production' environment toggle that
            // derives all three API/OAuth URLs.
            if ($env = $get('freeagent_env')) {
                $base = $env === 'production'
                    ? 'https://api.freeagent.com/v2'
                    : 'https://api.sandbox.freeagent.com/v2';

                config([
                    'filament-freeagent.environment' => $env,
                    'filament-freeagent.api_url' => $base,
                    'filament-freeagent.authorize_url' => $base.'/approve_app',
                    'filament-freeagent.token_url' => $base.'/token_endpoint',
                ]);
            }

            // Backward-compatible: explicit URL overrides (legacy host settings).
            if ($apiUrl = $get('freeagent_api_url')) {
                config(['filament-freeagent.api_url' => $apiUrl]);
            }

            if ($oauthUrl = $get('freeagent_oauth_url')) {
                config(['filament-freeagent.oauth_url' => $oauthUrl]);
                config(['filament-freeagent.authorize_url' => $oauthUrl.'/v2/approve_app']);
                config(['filament-freeagent.token_url' => $oauthUrl.'/v2/token_endpoint']);
            }
        } catch (\Exception $e) {
            // Silently fail if settings table doesn't exist yet (during migration)
            // Config will use default env() values
        }
    }
}
