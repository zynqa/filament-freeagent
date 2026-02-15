<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentOAuthException;
use Zynqa\FilamentFreeAgent\Models\FreeAgentOAuthToken;

class FreeAgentOAuthService
{
    private readonly string $authorizeUrl;

    private readonly string $tokenUrl;

    private readonly string $redirectUri;

    public function __construct()
    {
        $this->authorizeUrl = $this->resolveConfigValue('filament-freeagent.authorize_url');
        $this->tokenUrl = $this->resolveConfigValue('filament-freeagent.token_url');
        $this->redirectUri = $this->resolveConfigValue('filament-freeagent.redirect_uri');
    }

    /**
     * Resolve a config value that may be a closure
     */
    private function resolveConfigValue(string $key): string
    {
        $value = config($key);

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Get the OAuth client ID
     */
    private function getClientId(): ?string
    {
        $value = config('filament-freeagent.client_id');

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Get the OAuth client secret
     */
    private function getClientSecret(): ?string
    {
        $value = config('filament-freeagent.client_secret');

        return is_callable($value) ? $value() : $value;
    }

    /**
     * Generate the authorization URL for OAuth flow
     *
     * @param  string  $state  CSRF protection state parameter
     * @return string The authorization URL
     *
     * @throws FreeAgentOAuthException
     */
    public function getAuthorizationUrl(string $state): string
    {
        $clientId = $this->getClientId();

        if (! $clientId) {
            throw FreeAgentOAuthException::authorizationFailed('Client ID not configured');
        }

        $params = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
        ]);

        return "{$this->authorizeUrl}?{$params}";
    }

    /**
     * Handle OAuth callback and exchange code for tokens
     *
     * @param  string  $code  The authorization code from FreeAgent
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user  The user to associate the token with
     * @return FreeAgentOAuthToken The created or updated OAuth token
     *
     * @throws FreeAgentOAuthException
     */
    public function handleCallback(string $code, $user): FreeAgentOAuthToken
    {
        try {
            $response = Http::timeout(30)
                ->asForm()
                ->post($this->tokenUrl, [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                    'redirect_uri' => $this->redirectUri,
                ]);

            if (! $response->successful()) {
                Log::error('FreeAgent OAuth callback failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw FreeAgentOAuthException::authorizationFailed(
                    "HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (! isset($data['access_token'], $data['refresh_token'], $data['expires_in'])) {
                throw FreeAgentOAuthException::invalidCallback('Missing required token data');
            }

            return $this->storeTokens(
                $user,
                $data['access_token'],
                $data['refresh_token'],
                $data['expires_in']
            );

        } catch (RequestException $e) {
            Log::error('FreeAgent OAuth request exception', [
                'error' => $e->getMessage(),
            ]);

            throw FreeAgentOAuthException::authorizationFailed($e->getMessage());
        }
    }

    /**
     * Get a valid access token for the user, refreshing if necessary
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     *
     * @throws FreeAgentOAuthException
     */
    public function getValidAccessToken($user = null): ?FreeAgentOAuthToken
    {
        // Use system-wide connection (user_id = 1) instead of per-user tokens
        $token = FreeAgentOAuthToken::forUser(1)
            ->latest()
            ->first();

        if (! $token) {
            return null;
        }

        // If token is still valid and not expiring soon, return it
        if ($token->isValid() && ! $token->isExpiringSoon()) {
            return $token;
        }

        // Token is expired or expiring soon, attempt refresh
        try {
            return $this->refreshAccessToken($token);
        } catch (FreeAgentOAuthException $e) {
            Log::warning('Failed to refresh FreeAgent token', [
                'system_token' => true,
                'error' => $e->getMessage(),
            ]);

            // Delete the invalid token
            $token->delete();

            return null;
        }
    }

    /**
     * Refresh an expired or expiring access token
     *
     * @param  FreeAgentOAuthToken  $token  The token to refresh
     * @return FreeAgentOAuthToken The updated token
     *
     * @throws FreeAgentOAuthException
     */
    public function refreshAccessToken(FreeAgentOAuthToken $token): FreeAgentOAuthToken
    {
        try {
            $response = Http::timeout(30)
                ->asForm()
                ->post($this->tokenUrl, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $token->refresh_token,
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                ]);

            if (! $response->successful()) {
                Log::error('FreeAgent token refresh failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw FreeAgentOAuthException::refreshFailed(
                    "HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (! isset($data['access_token'], $data['refresh_token'], $data['expires_in'])) {
                throw FreeAgentOAuthException::refreshFailed('Missing required token data');
            }

            // Update the existing token
            $token->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'expires_at' => Carbon::now()->addSeconds($data['expires_in']),
            ]);

            return $token->fresh();

        } catch (RequestException $e) {
            Log::error('FreeAgent token refresh request exception', [
                'error' => $e->getMessage(),
            ]);

            throw FreeAgentOAuthException::refreshFailed($e->getMessage());
        }
    }

    /**
     * Store OAuth tokens for system-wide use
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user  (optional, for compatibility)
     * @param  int  $expiresIn  Seconds until expiration
     */
    private function storeTokens($user, string $accessToken, string $refreshToken, int $expiresIn): FreeAgentOAuthToken
    {
        // Delete any existing system tokens (user_id = 1 for app-wide connection)
        FreeAgentOAuthToken::where('user_id', 1)->delete();

        return FreeAgentOAuthToken::create([
            'user_id' => 1, // System-wide connection
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => Carbon::now()->addSeconds($expiresIn),
        ]);
    }

    /**
     * Revoke and delete the system-wide OAuth token
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user  (optional, for compatibility)
     */
    public function revokeToken($user = null): void
    {
        FreeAgentOAuthToken::where('user_id', 1)->delete();

        Log::info('FreeAgent OAuth token revoked', [
            'system_token' => true,
        ]);
    }
}
