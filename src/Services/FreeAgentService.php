<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Services;

use Exception;
use Illuminate\Contracts\Auth\Authenticatable;
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
     * @param  Authenticatable  $user  User for OAuth token
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
     * @param  Authenticatable  $user  User for OAuth token
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
     * @param  Authenticatable  $user  User for OAuth token
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
                    throw new Exception('PDF content not found in FreeAgent response');
                }

                // Decode base64 to get actual PDF binary
                $pdfContent = base64_decode($data['pdf']['content']);

                if ($pdfContent === false) {
                    throw new Exception('Failed to decode PDF content');
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
     * @param  Authenticatable  $user  User for OAuth token
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
     * @param  Authenticatable  $user  User for OAuth token
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
     * @param  Authenticatable  $user  User for OAuth token
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
     * @param  Authenticatable  $user  User for OAuth token
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
     * Create an invoice in FreeAgent.
     *
     * @param  Authenticatable  $user  User for OAuth token
     * @param  array  $invoice  Invoice payload (the inner "invoice" object), e.g.
     *                          ['contact' => '<contact url>', 'dated_on' => '2026-06-14',
     *                          'payment_terms_in_days' => 30, 'reference' => 'INV-2026-0001',
     *                          'currency' => 'GBP', 'invoice_items' => [...]]
     * @return array The created invoice data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function createInvoice($user, array $invoice): array
    {
        $response = $this->sendRequest('POST', 'invoices', $user, ['invoice' => $invoice]);

        $this->clearUserCache($user->id);

        return $response['invoice'] ?? [];
    }

    /**
     * Update an existing (draft) invoice in FreeAgent.
     *
     * @param  Authenticatable  $user  User for OAuth token
     * @param  string  $invoiceId  FreeAgent invoice ID (numeric or full URL)
     * @param  array  $invoice  Invoice fields to update (the inner "invoice" object)
     * @return array The updated invoice data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function updateInvoice($user, string $invoiceId, array $invoice): array
    {
        $id = $this->extractIdFromUrl($invoiceId);

        $response = $this->sendRequest('PUT', "invoices/{$id}", $user, ['invoice' => $invoice]);

        $this->clearUserCache($user->id);

        return $response['invoice'] ?? [];
    }

    /**
     * Transition an invoice to "Sent" without emailing the contact.
     *
     * @param  Authenticatable  $user  User for OAuth token
     * @param  string  $invoiceId  FreeAgent invoice ID (numeric or full URL)
     * @return array The updated invoice data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function markInvoiceAsSent($user, string $invoiceId): array
    {
        $id = $this->extractIdFromUrl($invoiceId);

        $response = $this->sendRequest('PUT', "invoices/{$id}/transitions/mark_as_sent", $user);

        $this->clearUserCache($user->id);

        return $response['invoice'] ?? [];
    }

    /**
     * Delete an invoice in FreeAgent. Only draft invoices can be deleted;
     * FreeAgent rejects deletion of sent/paid invoices. A 404 (already gone)
     * surfaces as a FreeAgentApiException with statusCode 404 for the caller
     * to treat as a no-op if desired.
     *
     * @param  Authenticatable  $user  User for OAuth token
     * @param  string  $invoiceId  FreeAgent invoice ID (numeric or full URL)
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function deleteInvoice($user, string $invoiceId): void
    {
        $id = $this->extractIdFromUrl($invoiceId);

        $this->sendRequest('DELETE', "invoices/{$id}", $user);

        $this->clearUserCache($user->id);
    }

    /**
     * Email an invoice to its contact via FreeAgent. This transitions the
     * invoice to "Sent" and lets FreeAgent deliver the email. Pass an empty
     * $email array to use FreeAgent's default template and the contact's email.
     *
     * @param  Authenticatable  $user  User for OAuth token
     * @param  string  $invoiceId  FreeAgent invoice ID (numeric or full URL)
     * @param  array  $email  Optional overrides: ['to' => ..., 'subject' => ..., 'body' => ...]
     * @return array The updated invoice data
     *
     * @throws FreeAgentApiException|FreeAgentOAuthException
     */
    public function sendInvoiceEmail($user, string $invoiceId, array $email = []): array
    {
        $id = $this->extractIdFromUrl($invoiceId);

        // FreeAgent expects the email payload nested under "invoice" => "email".
        // With use_email defaulting, an empty email object sends the default template.
        $payload = ['invoice' => ['email' => empty($email) ? (object) [] : $email]];

        $response = $this->sendRequest('POST', "invoices/{$id}/send_email", $user, $payload);

        $this->clearUserCache($user->id);

        return $response['invoice'] ?? [];
    }

    /**
     * Send an HTTP request to FreeAgent API with comprehensive error handling
     *
     * @param  string  $method  HTTP method (GET, POST, etc.)
     * @param  string  $endpoint  API endpoint (without base URL)
     * @param  Authenticatable  $user  User for OAuth token
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
     * @param  Authenticatable  $user  User for OAuth token
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
     * Build cache key for a request.
     *
     * Keys embed a per-user cache version so the whole set can be invalidated
     * by bumping the version (see clearUserCache) without flushing the host
     * application's cache or relying on a tag-aware cache store.
     */
    private function buildCacheKey(string $type, int $userId, array $params = []): string
    {
        $paramsHash = md5(json_encode($params));
        $version = $this->cacheVersion($userId);

        return "freeagent_v{$version}_{$type}_user_{$userId}_{$paramsHash}";
    }

    /**
     * Current cache version for a user (defaults to 1).
     */
    private function cacheVersion(int $userId): int
    {
        return (int) Cache::get("freeagent_cache_version_user_{$userId}", 1);
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
     * Invalidate all FreeAgent caches for a user.
     *
     * Bumps the user's cache version so every previously-built key becomes
     * unreachable. This is store-agnostic and, crucially, does NOT flush the
     * host application's cache (the previous implementation called
     * Cache::flush(), wiping unrelated cache entries).
     */
    public function clearUserCache(int $userId): void
    {
        $versionKey = "freeagent_cache_version_user_{$userId}";

        Cache::put($versionKey, $this->cacheVersion($userId) + 1);

        Log::info('FreeAgent cache cleared', [
            'user_id' => $userId,
        ]);
    }
}
