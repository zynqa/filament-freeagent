<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Filament\Resources\FreeAgentInvoiceResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentApiException;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentOAuthException;
use Zynqa\FilamentFreeAgent\Filament\Resources\FreeAgentInvoiceResource;
use Zynqa\FilamentFreeAgent\Services\FreeAgentCacheService;

class ListFreeAgentInvoices extends ListRecords
{
    protected static string $resource = FreeAgentInvoiceResource::class;

    public function mount(): void
    {
        parent::mount();

        // On-access sync: check if cache is stale and sync if needed
        $this->syncIfStale();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync Invoices')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function () {
                    $this->syncInvoices(true);
                })
                ->requiresConfirmation()
                ->modalHeading('Sync Invoices')
                ->modalDescription('This will fetch the latest invoice data. This may take a few moments.')
                ->modalSubmitActionLabel('Sync Now'),

            Action::make('settings')
                ->label('FreeAgent Settings')
                ->icon('heroicon-o-cog-6-tooth')
                ->url(fn (): ?string => static::settingsUrl())
                ->visible(fn (): bool => (auth()->user()?->hasRole('super_admin') ?? false)
                    && static::settingsUrl() !== null)
                ->color('gray'),
        ];
    }

    /**
     * Resolve the host application's settings page URL from config.
     *
     * Returns null when the configured route is not registered, so the action
     * hides instead of throwing in apps that don't expose a settings page.
     */
    protected static function settingsUrl(): ?string
    {
        $route = config('filament-freeagent.routes.settings', 'filament.app.pages.manage-general-settings');

        return Route::has($route)
            ? route($route).'?tab=-integrations-tab'
            : null;
    }

    /**
     * Sync invoices if cache is stale (on page access)
     */
    protected function syncIfStale(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        // Skip if user doesn't have FreeAgent connection
        if (! method_exists($user, 'hasFreeAgentConnection') || ! $user->hasFreeAgentConnection()) {
            return;
        }

        try {
            $cacheService = app(FreeAgentCacheService::class);

            // Only sync if cache is stale (older than 30 minutes)
            if ($cacheService->isInvoicesCacheStale($user->id)) {
                $this->syncInvoices(false);
            }
        } catch (\Exception $e) {
            // Silent fail - don't interrupt page load
            Log::warning('FreeAgent on-access sync check failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Perform invoice sync
     */
    protected function syncInvoices(bool $showNotification = true): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        try {
            $cacheService = app(FreeAgentCacheService::class);

            // Build filters based on user permissions
            $filters = [];

            // Regular users: filter by contact
            if (! $user->hasRole('super_admin')) {
                if (method_exists($user, 'getFreeAgentContactId')) {
                    $contactId = $user->getFreeAgentContactId();

                    // Only sync if contact is set
                    if ($contactId) {
                        $filters['contact'] = $contactId;
                    } else {
                        // User not linked - don't sync anything
                        if ($showNotification) {
                            Notification::make()
                                ->title('Setup Required')
                                ->body('Your account is not set up for invoices yet. Please contact your administrator.')
                                ->warning()
                                ->send();
                        }

                        return;
                    }
                } else {
                    return; // Method missing, don't sync
                }
            }
            // Super admins sync all invoices (no filters)

            $stats = $cacheService->syncInvoices($user, $filters);

            if ($showNotification) {
                Notification::make()
                    ->success()
                    ->title('Invoices Synced')
                    ->body("Synced {$stats['total']} invoices ({$stats['created']} new, {$stats['updated']} updated)")
                    ->send();
            }

            // Refresh the table to show new data
            $this->dispatch('$refresh');

        } catch (FreeAgentOAuthException $e) {
            Log::error('FreeAgent OAuth error during sync', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            if ($showNotification) {
                // Only an administrator can authorise the accounting integration, and only
                // an administrator should be told that is what is missing. A client gets a
                // message they can act on — and no button into an OAuth flow that would
                // fail for them.
                $isAdmin = auth()->user()?->hasRole('super_admin') ?? false;

                $notification = Notification::make()
                    ->danger()
                    ->title($isAdmin ? 'Connection Required' : 'Invoices Unavailable')
                    ->body($isAdmin
                        ? 'Connect the accounting integration to sync invoices.'
                        : 'Invoices are temporarily unavailable. Please contact your administrator.');

                if ($isAdmin) {
                    $notification->actions([
                        Action::make('connect')
                            ->button()
                            ->url(route('freeagent.connect')),
                    ]);
                }

                $notification->send();
            }
        } catch (FreeAgentApiException $e) {
            Log::error('FreeAgent API error during sync', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            if ($showNotification) {
                Notification::make()
                    ->danger()
                    ->title('Sync Failed')
                    ->body('Unable to sync invoices. Please try again later.')
                    ->send();
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error during FreeAgent sync', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            if ($showNotification) {
                Notification::make()
                    ->danger()
                    ->title('Sync Error')
                    ->body('An unexpected error occurred while syncing invoices')
                    ->send();
            }
        }
    }
}
