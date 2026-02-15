<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentApiException;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentOAuthException;

class FreeAgentService
{
    private readonly string $apiUrl;

    private readonly int $invoicesCacheTtl;

    private readonly int $contactsCacheTtl;

    public function __construct(
        private readonly FreeAgentOAuthService $oauthService
    ) {
        $apiUrl = config('filament-freeagent.api_url');
        $this->apiUrl = is_callable($apiUrl) ? $apiUrl() : $apiUrl;
        $this->invoicesCacheTtl = config('filament-freeagent.cache.invoices_ttl', 1800);
        $this->contactsCacheTtl = config('filament-freeagent.cache.contacts_ttl', 3600);
    }

    /**
     * Get all invoices, optionally filtered
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  array  $filters  Optional filters (contact, view, from_date, to_date)
     * @param  bool  $useCache  Whether to use cached results
     * @return array Array of invoice data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function getInvoices($user, array $filters = [], bool $useCache = true): array
    {
        $cacheKey = $this->buildCacheKey('invoices', $user->id, $filters);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Fetch all pages using pagination
        $invoices = $this->fetchAllPages('invoices', $user, $filters);

        Cache::put($cacheKey, $invoices, $this->invoicesCacheTtl);

        return $invoices;
    }

    /**
     * Get a specific invoice by ID
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  string  $invoiceId  FreeAgent invoice ID (full URL)
     * @param  bool  $useCache  Whether to use cached results
     * @return array Invoice data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function getInvoice($user, string $invoiceId, bool $useCache = true): array
    {
        $cacheKey = $this->buildCacheKey('invoice', $user->id, ['id' => $invoiceId]);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Extract ID from URL if needed
        $id = $this->extractIdFromUrl($invoiceId);

        $response = $this->sendRequest(
            'GET',
            "invoices/{$id}",
            $user
        );

        $invoice = $response['invoice'] ?? [];

        Cache::put($cacheKey, $invoice, $this->invoicesCacheTtl);

        return $invoice;
    }

    /**
     * Get PDF content for an invoice
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  string  $invoiceId  FreeAgent invoice ID
     * @return string PDF binary content
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function getInvoicePdf($user, string $invoiceId): string
    {
        $id = $this->extractIdFromUrl($invoiceId);

        $token = $this->oauthService->getValidAccessToken($user);

        if (! $token) {
            throw FreeAgentOAuthException::noTokenAvailable();
        }

        try {
            // FreeAgent returns PDF as base64-encoded JSON, not raw binary
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withToken($token->access_token)
                ->accept('application/json') // Changed from application/pdf
                ->get("{$this->apiUrl}/invoices/{$id}/pdf");

            if ($response->successful()) {
                $data = $response->json();

                // Extract base64-encoded PDF content
                if (! isset($data['pdf']['content'])) {
                    throw new \Exception('PDF content not found in FreeAgent response');
                }

                // Decode base64 to get actual PDF binary
                $pdfContent = base64_decode($data['pdf']['content']);

                if ($pdfContent === false) {
                    throw new \Exception('Failed to decode PDF content');
                }

                return $pdfContent;
            }

            throw FreeAgentApiException::requestFailed($response->status());
        } catch (RequestException $e) {
            Log::error('FreeAgent PDF download failed', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw FreeAgentApiException::networkError($e->getMessage());
        }
    }

    /**
     * Get all contacts
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  array  $filters  Optional filters (view)
     * @param  bool  $useCache  Whether to use cached results
     * @return array Array of contact data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function getContacts($user, array $filters = [], bool $useCache = true): array
    {
        $cacheKey = $this->buildCacheKey('contacts', $user->id, $filters);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Fetch all pages using pagination
        $contacts = $this->fetchAllPages('contacts', $user, $filters);

        Cache::put($cacheKey, $contacts, $this->contactsCacheTtl);

        return $contacts;
    }

    /**
     * Get a specific contact by ID
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  string  $contactId  FreeAgent contact ID (full URL)
     * @param  bool  $useCache  Whether to use cached results
     * @return array Contact data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function getContact($user, string $contactId, bool $useCache = true): array
    {
        $cacheKey = $this->buildCacheKey('contact', $user->id, ['id' => $contactId]);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $id = $this->extractIdFromUrl($contactId);

        $response = $this->sendRequest(
            'GET',
            "contacts/{$id}",
            $user
        );

        $contact = $response['contact'] ?? [];

        Cache::put($cacheKey, $contact, $this->contactsCacheTtl);

        return $contact;
    }

    /**
     * Get all projects
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  array  $filters  Optional filters (contact, view)
     * @param  bool  $useCache  Whether to use cached results
     * @return array Array of project data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function getProjects($user, array $filters = [], bool $useCache = true): array
    {
        $cacheKey = $this->buildCacheKey('projects', $user->id, $filters);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Fetch all pages using pagination
        $projects = $this->fetchAllPages('projects', $user, $filters);

        Cache::put($cacheKey, $projects, $this->contactsCacheTtl);

        return $projects;
    }

    /**
     * Get a specific project by ID
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  string  $projectId  FreeAgent project ID (full URL)
     * @param  bool  $useCache  Whether to use cached results
     * @return array Project data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function getProject($user, string $projectId, bool $useCache = true): array
    {
        $cacheKey = $this->buildCacheKey('project', $user->id, ['id' => $projectId]);

        if ($useCache) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $id = $this->extractIdFromUrl($projectId);

        $response = $this->sendRequest(
            'GET',
            "projects/{$id}",
            $user
        );

        $project = $response['project'] ?? [];

        Cache::put($cacheKey, $project, $this->contactsCacheTtl);

        return $project;
    }

    /**
     * Send an HTTP request to FreeAgent API with comprehensive error handling
     *
     * @param  string  $method  HTTP method (GET, POST, etc.)
     * @param  string  $endpoint  API endpoint (without base URL)
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  array  $params  Query parameters or request body
     * @return array Response data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    private function sendRequest(string $method, string $endpoint, $user, array $params = []): array
    {
        $startTime = microtime(true);

        // Check rate limiting
        $rateLimitKey = "freeagent_api_rate_limit_{$user->id}";
        if (! RateLimiter::attempt($rateLimitKey, 120, function () {})) {
            throw FreeAgentApiException::rateLimitExceeded();
        }

        // Get valid OAuth token (auto-refreshes if needed)
        $token = $this->oauthService->getValidAccessToken($user);

        if (! $token) {
            throw FreeAgentOAuthException::noTokenAvailable();
        }

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->retry(3, 1000, function ($exception) {
                    return $exception instanceof ConnectionException;
                })
                ->withToken($token->access_token)
                ->accept('application/json')
                ->{strtolower($method)}("{$this->apiUrl}/{$endpoint}", $params);

            $duration = (microtime(true) - $startTime) * 1000;

            $this->logApiRequest($method, $endpoint, $duration, $response->status(), true);

            return $this->handleResponse($response);

        } catch (RequestException $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $statusCode = $e->response?->status() ?? 0;

            $this->logApiRequest($method, $endpoint, $duration, $statusCode, false, $e->getMessage());

            throw FreeAgentApiException::requestFailed($statusCode, $e->response?->json());
        } catch (ConnectionException $e) {
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logApiRequest($method, $endpoint, $duration, 0, false, $e->getMessage());

            throw FreeAgentApiException::networkError($e->getMessage());
        }
    }

    /**
     * Handle API response and check for errors
     *
     * @throws FreeAgentApiException
     */
    private function handleResponse(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        // Handle specific error codes
        $statusCode = $response->status();
        $responseData = $response->json();

        if ($statusCode === 401) {
            throw FreeAgentApiException::authenticationFailed();
        }

        if ($statusCode === 429) {
            throw FreeAgentApiException::rateLimitExceeded();
        }

        throw FreeAgentApiException::requestFailed($statusCode, $responseData);
    }

    /**
     * Fetch all pages from a paginated endpoint
     *
     * @param  string  $endpoint  API endpoint (invoices, contacts, etc.)
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  User for OAuth token
     * @param  array  $filters  Optional filters
     * @return array Combined results from all pages
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    private function fetchAllPages(string $endpoint, $user, array $filters = []): array
    {
        $allResults = [];
        $page = 1;
        $perPage = 100; // Maximum allowed by FreeAgent API
        $hasMorePages = true;

        // Build query parameters based on endpoint
        if ($endpoint === 'invoices') {
            $baseParams = $this->buildInvoiceQueryParams($filters);
        } elseif ($endpoint === 'contacts') {
            $baseParams = isset($filters['view']) ? ['view' => $filters['view']] : [];
        } elseif ($endpoint === 'projects') {
            $baseParams = [];
            if (isset($filters['contact'])) {
                $baseParams['contact'] = $filters['contact'];
            }
            if (isset($filters['view'])) {
                $baseParams['view'] = $filters['view'];
            }
        } else {
            $baseParams = [];
        }

        while ($hasMorePages) {
            $queryParams = array_merge($baseParams, [
                'page' => $page,
                'per_page' => $perPage,
            ]);

            $response = $this->sendRequest(
                'GET',
                $endpoint,
                $user,
                $queryParams
            );

            // Extract results based on endpoint
            $results = $response[$endpoint] ?? [];

            if (empty($results)) {
                $hasMorePages = false;
            } else {
                $allResults = array_merge($allResults, $results);

                // If we got fewer results than per_page, we've reached the last page
                if (count($results) < $perPage) {
                    $hasMorePages = false;
                } else {
                    $page++;
                }
            }

            // Safety limit: prevent infinite loops
            if ($page > 1000) {
                Log::warning('FreeAgent pagination safety limit reached', [
                    'endpoint' => $endpoint,
                    'page' => $page,
                    'total_results' => count($allResults),
                ]);
                break;
            }
        }

        Log::info('FreeAgent pagination completed', [
            'endpoint' => $endpoint,
            'total_pages' => $page,
            'total_results' => count($allResults),
        ]);

        return $allResults;
    }

    /**
     * Log API request for monitoring and debugging
     */
    private function logApiRequest(
        string $method,
        string $endpoint,
        float $duration,
        int $statusCode,
        bool $success,
        ?string $error = null
    ): void {
        $level = $success ? 'info' : 'error';

        Log::$level('FreeAgent API Request', [
            'method' => $method,
            'endpoint' => $endpoint,
            'duration_ms' => round($duration, 2),
            'status_code' => $statusCode,
            'success' => $success,
            'error' => $error,
        ]);
    }

    /**
     * Build query parameters for invoice filtering
     */
    private function buildInvoiceQueryParams(array $filters): array
    {
        $params = [];

        if (isset($filters['contact'])) {
            $params['contact'] = $filters['contact'];
        }

        if (isset($filters['view'])) {
            $params['view'] = $filters['view'];
        }

        if (isset($filters['from_date'])) {
            $params['from_date'] = $filters['from_date'];
        }

        if (isset($filters['to_date'])) {
            $params['to_date'] = $filters['to_date'];
        }

        return $params;
    }

    /**
     * Build cache key for a request
     */
    private function buildCacheKey(string $type, int $userId, array $params = []): string
    {
        $paramsHash = md5(json_encode($params));

        return "freeagent_{$type}_user_{$userId}_{$paramsHash}";
    }

    /**
     * Extract numeric ID from FreeAgent URL
     * FreeAgent returns URLs like "https://api.freeagent.com/v2/invoices/123"
     */
    private function extractIdFromUrl(string $urlOrId): string
    {
        if (! str_contains($urlOrId, '/')) {
            return $urlOrId;
        }

        $parts = explode('/', $urlOrId);

        return end($parts);
    }

    /**
     * Clear all caches for a user
     */
    public function clearUserCache(int $userId): void
    {
        // This is a simple implementation - in production you might use cache tags
        // or maintain a list of cache keys per user
        Cache::flush(); // For simplicity, clear all cache

        Log::info('FreeAgent cache cleared', [
            'user_id' => $userId,
        ]);
    }
}
