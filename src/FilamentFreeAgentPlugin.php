<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Support\Facades\Gate;
use Zynqa\FilamentFreeAgent\Filament\Resources\FreeAgentInvoiceResource;
use Zynqa\FilamentFreeAgent\Models\FreeAgentInvoice;
use Zynqa\FilamentFreeAgent\Policies\FreeAgentInvoicePolicy;

class FilamentFreeAgentPlugin implements Plugin
{
    use EvaluatesClosures;

    public function getId(): string
    {
        return 'filament-freeagent';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                FreeAgentInvoiceResource::class,
            ]);
        // Note: FreeAgentSettingsForm can be embedded in app's settings page
        // This approach keeps navigation clean and provides better integration
    }

    public function boot(Panel $panel): void
    {
        // Register policy
        Gate::policy(
            FreeAgentInvoice::class,
            FreeAgentInvoicePolicy::class
        );
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        return filament(app(static::class)->getId());
    }
}
