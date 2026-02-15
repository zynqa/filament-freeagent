<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Filament\Forms;

use Filament\Forms;
use Illuminate\Support\Facades\Cache;
use Zynqa\FilamentFreeAgent\Services\FreeAgentCacheService;

/**
 * Provides FreeAgent settings form components that can be embedded
 * in any Filament settings page (e.g., General Settings).
 *
 * This allows apps to integrate FreeAgent settings as a tab
 * in their existing settings pages without creating a separate page.
 */
class FreeAgentSettingsForm
{
    /**
     * Get the complete schema for FreeAgent settings tab
     * Use this to embed FreeAgent settings in your app's settings page
     */
    public static function getSchema(): array
    {
        return [
            Forms\Components\Section::make('OAuth Configuration')
                ->description('Configure FreeAgent OAuth credentials for API access')
                ->collapsible()
                ->schema([
                    Forms\Components\TextInput::make('client_id')
                        ->label('Client ID')
                        ->helperText('Your FreeAgent OAuth application Client ID')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('client_secret')
                        ->label('Client Secret')
                        ->helperText('Your FreeAgent OAuth application Client Secret')
                        ->password()
                        ->revealable()
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Placeholder::make('redirect_uri')
                        ->label('Redirect URI')
                        ->content(fn () => config('filament-freeagent.redirect_uri'))
                        ->helperText('Use this URL when configuring your FreeAgent OAuth application'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Connection Information')
                ->description('Current FreeAgent connection details')
                ->collapsible()
                ->schema([
                    Forms\Components\Placeholder::make('environment')
                        ->label('Environment')
                        ->content(fn () => ucfirst(config('filament-freeagent.environment'))),

                    Forms\Components\Placeholder::make('api_url')
                        ->label('API URL')
                        ->content(fn () => config('filament-freeagent.api_url')),

                    Forms\Components\Placeholder::make('connection_status')
                        ->label('Your Connection Status')
                        ->content(function () {
                            $user = auth()->user();

                            if (! $user) {
                                return '❌ Not authenticated';
                            }

                            if (method_exists($user, 'hasFreeAgentConnection') && $user->hasFreeAgentConnection()) {
                                return '✅ Connected';
                            }

                            return '⚠️ Not connected';
                        }),
                ])
                ->columns(2),

            Forms\Components\Section::make('User Connection')
                ->description('Connect your FreeAgent account to access invoices')
                ->collapsible()
                ->schema([
                    Forms\Components\Placeholder::make('oauth_info')
                        ->label('OAuth Connection')
                        ->content(function () {
                            $user = auth()->user();

                            if (! $user) {
                                return 'Please log in to connect your FreeAgent account';
                            }

                            if (method_exists($user, 'hasFreeAgentConnection') && $user->hasFreeAgentConnection()) {
                                return '✅ Your FreeAgent account is connected and ready to use';
                            }

                            return 'Click the "Connect FreeAgent" button below to authorize access to your FreeAgent invoices';
                        })
                        ->columnSpanFull(),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('connect')
                            ->label('Connect FreeAgent')
                            ->icon('heroicon-o-link')
                            ->color('success')
                            ->url(fn () => route('freeagent.connect'))
                            ->visible(function () {
                                $user = auth()->user();

                                return ! (method_exists($user, 'hasFreeAgentConnection') && $user->hasFreeAgentConnection());
                            }),

                        Forms\Components\Actions\Action::make('disconnect')
                            ->label('Disconnect FreeAgent')
                            ->icon('heroicon-o-x-mark')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalHeading('Disconnect FreeAgent')
                            ->modalDescription('Are you sure you want to disconnect your FreeAgent account? You will need to reconnect to access invoices.')
                            ->action(function () {
                                $user = auth()->user();
                                if ($user) {
                                    app(\Zynqa\FilamentFreeAgent\Services\FreeAgentOAuthService::class)
                                        ->revokeToken($user);

                                    \Filament\Notifications\Notification::make()
                                        ->title('Disconnected')
                                        ->body('Your FreeAgent account has been disconnected')
                                        ->success()
                                        ->send();
                                }
                            })
                            ->visible(function () {
                                $user = auth()->user();

                                return method_exists($user, 'hasFreeAgentConnection') && $user->hasFreeAgentConnection();
                            }),
                    ]),
                ]),

            Forms\Components\Section::make('Cache Management')
                ->description('Clear FreeAgent caches to force fresh data from the API')
                ->collapsible()
                ->schema([
                    Forms\Components\Placeholder::make('cache_info')
                        ->label('Cache Status')
                        ->content(function () {
                            $user = auth()->user();

                            if (! $user) {
                                return '❌ Not authenticated';
                            }

                            $cacheService = app(FreeAgentCacheService::class);

                            if ($cacheService->isInvoicesCacheStale($user->id)) {
                                return '⚠️ Cache is stale (will sync on next access)';
                            }

                            return '✅ Cache is fresh';
                        })
                        ->columnSpanFull(),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('clear_my_cache')
                            ->label('Clear My Cache')
                            ->icon('heroicon-o-trash')
                            ->color('warning')
                            ->requiresConfirmation()
                            ->modalHeading('Clear My FreeAgent Cache')
                            ->modalDescription('This will clear your cached FreeAgent data and fetch fresh data on your next visit to the invoices section.')
                            ->action(function () {
                                static::clearUserCache();
                            }),

                        Forms\Components\Actions\Action::make('clear_all_cache')
                            ->label('Clear All Users Cache')
                            ->icon('heroicon-o-trash')
                            ->color('danger')
                            ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                            ->requiresConfirmation()
                            ->modalHeading('Clear All FreeAgent Cache')
                            ->modalDescription('This will clear the FreeAgent cache for ALL users. This is a system-wide operation.')
                            ->action(function () {
                                static::clearAllCache();
                            }),
                    ]),
                ]),
        ];
    }

    /**
     * Clear the current user's FreeAgent cache
     */
    protected static function clearUserCache(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $cacheService = app(FreeAgentCacheService::class);
        $cacheService->clearUserCache($user->id);

        \Filament\Notifications\Notification::make()
            ->title('Cache Cleared Successfully')
            ->body('Your FreeAgent cache has been cleared. Fresh data will be loaded on your next visit.')
            ->success()
            ->send();
    }

    /**
     * Clear all FreeAgent caches for all users (admin only)
     */
    protected static function clearAllCache(): void
    {
        Cache::flush(); // Simple implementation - in production might be more selective

        \Filament\Notifications\Notification::make()
            ->title('All Cache Cleared')
            ->body('All FreeAgent caches have been cleared system-wide.')
            ->success()
            ->send();
    }
}
