<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentApiException;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentOAuthException;
use Zynqa\FilamentFreeAgent\Models\FreeAgentContact;
use Zynqa\FilamentFreeAgent\Models\FreeAgentInvoice;
use Zynqa\FilamentFreeAgent\Models\FreeAgentProject;

class FreeAgentCacheService
{
    private const CACHE_TTL = 1800; // 30 minutes

    public function __construct(
        private readonly FreeAgentService $freeAgentService
    ) {}

    /**
     * Check if contacts cache is stale for a user
     */
    public function isContactsCacheStale(int $userId): bool
    {
        $cacheKey = $this->getContactsCacheKey($userId);
        $lastSync = Cache::get($cacheKey);

        if (! $lastSync) {
            return true;
        }

        return Carbon::parse($lastSync)->addSeconds(self::CACHE_TTL)->isPast();
    }

    /**
     * Check if invoices cache is stale for a user
     */
    public function isInvoicesCacheStale(int $userId): bool
    {
        $cacheKey = $this->getInvoicesCacheKey($userId);
        $lastSync = Cache::get($cacheKey);

        if (! $lastSync) {
            return true;
        }

        return Carbon::parse($lastSync)->addSeconds(self::CACHE_TTL)->isPast();
    }

    /**
     * Sync contacts from FreeAgent API to local database
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User with OAuth token
     * @return array Sync statistics
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function syncContacts($user): array
    {
        $startTime = microtime(true);

        try {
            // Fetch all contacts from API (bypass cache)
            $apiContacts = $this->freeAgentService->getContacts($user, [], false);

            $stats = [
                'total' => count($apiContacts),
                'created' => 0,
                'updated' => 0,
                'errors' => 0,
            ];

            foreach ($apiContacts as $apiContact) {
                try {
                    $contact = FreeAgentContact::updateOrCreateFromApi($apiContact);

                    if ($contact->wasRecentlyCreated) {
                        $stats['created']++;
                    } else {
                        $stats['updated']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::error('Failed to sync FreeAgent contact', [
                        'contact_url' => $apiContact['url'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Mark sync as completed
            $this->markContactsSynced($user->id);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::info('FreeAgent contacts synced', [
                'user_id' => $user->id,
                'stats' => $stats,
                'duration_ms' => round($duration, 2),
            ]);

            return $stats;

        } catch (FreeAgentApiException|FreeAgentOAuthException $e) {
            Log::error('FreeAgent contacts sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync projects from FreeAgent API to local database
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User with OAuth token
     * @param  array  $filters  Optional filters to limit sync scope
     * @return array Sync statistics
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function syncProjects($user, array $filters = []): array
    {
        $startTime = microtime(true);

        try {
            // Ensure contacts are synced first
            if ($this->isContactsCacheStale($user->id)) {
                $this->syncContacts($user);
            }

            // Fetch all projects from API (bypass cache)
            $apiProjects = $this->freeAgentService->getProjects($user, $filters, false);

            $stats = [
                'total' => count($apiProjects),
                'created' => 0,
                'updated' => 0,
                'errors' => 0,
            ];

            foreach ($apiProjects as $apiProject) {
                try {
                    $project = FreeAgentProject::updateOrCreateFromApi($apiProject);

                    if ($project->wasRecentlyCreated) {
                        $stats['created']++;
                    } else {
                        $stats['updated']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::error('Failed to sync FreeAgent project', [
                        'project_url' => $apiProject['url'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $duration = (microtime(true) - $startTime) * 1000;

            Log::info('FreeAgent projects synced', [
                'user_id' => $user->id,
                'stats' => $stats,
                'duration_ms' => round($duration, 2),
            ]);

            return $stats;

        } catch (FreeAgentApiException|FreeAgentOAuthException $e) {
            Log::error('FreeAgent projects sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync invoices from FreeAgent API to local database
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User with OAuth token
     * @param  array  $filters  Optional filters to limit sync scope
     * @return array Sync statistics
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function syncInvoices($user, array $filters = []): array
    {
        $startTime = microtime(true);

        try {
            // Ensure contacts and projects are synced first
            if ($this->isContactsCacheStale($user->id)) {
                $this->syncContacts($user);
            }

            // Sync projects before invoices
            $this->syncProjects($user, $filters);

            // Fetch all invoices from API (bypass cache)
            $apiInvoices = $this->freeAgentService->getInvoices($user, $filters, false);

            $stats = [
                'total' => count($apiInvoices),
                'created' => 0,
                'updated' => 0,
                'errors' => 0,
            ];

            foreach ($apiInvoices as $apiInvoice) {
                try {
                    $invoice = FreeAgentInvoice::updateOrCreateFromApi($apiInvoice);

                    if ($invoice->wasRecentlyCreated) {
                        $stats['created']++;
                    } else {
                        $stats['updated']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::error('Failed to sync FreeAgent invoice', [
                        'invoice_url' => $apiInvoice['url'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Mark sync as completed
            $this->markInvoicesSynced($user->id);

            $duration = (microtime(true) - $startTime) * 1000;

            Log::info('FreeAgent invoices synced', [
                'user_id' => $user->id,
                'stats' => $stats,
                'duration_ms' => round($duration, 2),
            ]);

            return $stats;

        } catch (FreeAgentApiException|FreeAgentOAuthException $e) {
            Log::error('FreeAgent invoices sync failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sync a single invoice by ID
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function syncInvoice($user, string $invoiceId): FreeAgentInvoice
    {
        // Fetch invoice from API
        $apiInvoice = $this->freeAgentService->getInvoice($user, $invoiceId, false);

        // Ensure contact exists
        if (isset($apiInvoice['contact'])) {
            $contactData = $this->freeAgentService->getContact($user, $apiInvoice['contact'], false);
            FreeAgentContact::updateOrCreateFromApi($contactData);
        }

        return FreeAgentInvoice::updateOrCreateFromApi($apiInvoice);
    }

    /**
     * Mark contacts as synced for a user
     */
    private function markContactsSynced(int $userId): void
    {
        $cacheKey = $this->getContactsCacheKey($userId);
        Cache::put($cacheKey, now()->toIso8601String(), self::CACHE_TTL);
    }

    /**
     * Mark invoices as synced for a user
     */
    private function markInvoicesSynced(int $userId): void
    {
        $cacheKey = $this->getInvoicesCacheKey($userId);
        Cache::put($cacheKey, now()->toIso8601String(), self::CACHE_TTL);
    }

    /**
     * Get contacts cache key for a user
     */
    private function getContactsCacheKey(int $userId): string
    {
        return "freeagent_contacts_last_sync_user_{$userId}";
    }

    /**
     * Get invoices cache key for a user
     */
    private function getInvoicesCacheKey(int $userId): string
    {
        return "freeagent_invoices_last_sync_user_{$userId}";
    }

    /**
     * Clear all cache for a user
     */
    public function clearUserCache(int $userId): void
    {
        Cache::forget($this->getContactsCacheKey($userId));
        Cache::forget($this->getInvoicesCacheKey($userId));

        Log::info('FreeAgent cache cleared for user', [
            'user_id' => $userId,
        ]);
    }
}
