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
}
