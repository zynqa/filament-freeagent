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
            if (class_exists(\App\Settings\GeneralSettings::class)) {
                $settings = app(\App\Settings\GeneralSettings::class);

                // Override config with database settings if they exist
                if ($settings->freeagent_client_id) {
                    config(['filament-freeagent.client_id' => $settings->freeagent_client_id]);
                }

                if ($settings->freeagent_client_secret) {
                    config(['filament-freeagent.client_secret' => $settings->freeagent_client_secret]);
                }

                if ($settings->freeagent_api_url) {
                    config(['filament-freeagent.api_url' => $settings->freeagent_api_url]);
                }

                if ($settings->freeagent_oauth_url) {
                    config(['filament-freeagent.oauth_url' => $settings->freeagent_oauth_url]);
                    config(['filament-freeagent.authorize_url' => $settings->freeagent_oauth_url.'/v2/approve_app']);
                    config(['filament-freeagent.token_url' => $settings->freeagent_oauth_url.'/v2/token_endpoint']);
                }
            }
        } catch (\Exception $e) {
            // Silently fail if settings table doesn't exist yet (during migration)
            // Config will use default env() values
        }
    }
}
