<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Http\Controllers;

use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentApiException;
use Zynqa\FilamentFreeAgent\Exceptions\FreeAgentOAuthException;
use Zynqa\FilamentFreeAgent\Models\FreeAgentInvoice;
use Zynqa\FilamentFreeAgent\Services\FreeAgentOAuthService;
use Zynqa\FilamentFreeAgent\Services\FreeAgentService;

class FreeAgentOAuthController extends Controller
{
    public function __construct(
        private readonly FreeAgentOAuthService $oauthService,
        private readonly FreeAgentService $freeAgentService
    ) {}

    /**
     * Redirect to FreeAgent for authorization
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! auth()->check()) {
            return redirect()->route('filament.app.auth.login')
                ->with('error', 'You must be logged in to connect to FreeAgent');
        }

        try {
            // Generate and store CSRF state token
            $state = Str::random(40);
            session(['freeagent_oauth_state' => $state]);

            $authUrl = $this->oauthService->getAuthorizationUrl($state);

            return redirect($authUrl);

        } catch (FreeAgentOAuthException $e) {
            Log::error('FreeAgent OAuth redirect failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Connection Failed')
                ->body('Unable to connect to FreeAgent. Please try again.')
                ->danger()
                ->send();

            return redirect()->back();
        }
    }

    /**
     * Handle OAuth callback from FreeAgent
     */
    public function callback(Request $request): RedirectResponse
    {
        if (! auth()->check()) {
            return redirect()->route('filament.app.auth.login')
                ->with('error', 'Authentication required');
        }

        // Verify state to prevent CSRF attacks
        $sessionState = session('freeagent_oauth_state');
        $requestState = $request->get('state');

        if (! $sessionState || $sessionState !== $requestState) {
            Log::warning('FreeAgent OAuth state mismatch', [
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Security Error')
                ->body('Invalid OAuth state. Please try again.')
                ->danger()
                ->send();

            return redirect()->route('filament.app.pages.dashboard');
        }

        // Clear the state
        session()->forget('freeagent_oauth_state');

        // Check for errors
        if ($request->has('error')) {
            $error = $request->get('error');
            $errorDescription = $request->get('error_description', 'Unknown error');

            Log::warning('FreeAgent OAuth error', [
                'error' => $error,
                'description' => $errorDescription,
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Authorization Failed')
                ->body("FreeAgent returned an error: {$errorDescription}")
                ->danger()
                ->send();

            return redirect()->route('filament.app.pages.dashboard');
        }

        // Get authorization code
        $code = $request->get('code');

        if (! $code) {
            Notification::make()
                ->title('Authorization Failed')
                ->body('No authorization code received from FreeAgent')
                ->danger()
                ->send();

            return redirect()->route('filament.app.pages.dashboard');
        }

        try {
            // Exchange code for tokens
            $this->oauthService->handleCallback($code, auth()->user());

            Notification::make()
                ->title('Connected Successfully')
                ->body('Your FreeAgent account has been connected')
                ->success()
                ->send();

            Log::info('FreeAgent OAuth successful', [
                'user_id' => auth()->id(),
            ]);

            return redirect()->route('filament.app.pages.dashboard');

        } catch (FreeAgentOAuthException $e) {
            Log::error('FreeAgent OAuth callback failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Connection Failed')
                ->body('Unable to complete FreeAgent connection. Please try again.')
                ->danger()
                ->send();

            return redirect()->route('filament.app.pages.dashboard');
        }
    }

    /**
     * Disconnect FreeAgent account
     */
    public function disconnect(Request $request): RedirectResponse
    {
        if (! auth()->check()) {
            return redirect()->route('filament.app.auth.login');
        }

        try {
            $this->oauthService->revokeToken(auth()->user());

            Notification::make()
                ->title('Disconnected')
                ->body('Your FreeAgent account has been disconnected')
                ->success()
                ->send();

            return redirect()->back();

        } catch (\Exception $e) {
            Log::error('FreeAgent disconnect failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Error')
                ->body('Unable to disconnect FreeAgent account')
                ->danger()
                ->send();

            return redirect()->back();
        }
    }

    /**
     * Download invoice PDF
     */
    public function downloadInvoicePdf(Request $request, FreeAgentInvoice $invoice): Response|RedirectResponse
    {
        if (! auth()->check()) {
            abort(403, 'Authentication required');
        }

        // Check authorization
        if (! auth()->user()->can('downloadPdf', $invoice)) {
            abort(403, 'You are not authorized to download this invoice');
        }

        try {
            // Fetch PDF from FreeAgent
            $pdfContent = $this->freeAgentService->getInvoicePdf(
                auth()->user(),
                $invoice->freeagent_id
            );

            // Validate PDF content
            if (empty($pdfContent)) {
                throw new \Exception('Empty PDF content received from FreeAgent');
            }

            // Verify it's actually a PDF (check magic bytes)
            if (! str_starts_with($pdfContent, '%PDF')) {
                Log::error('Invalid PDF content received', [
                    'invoice_id' => $invoice->id,
                    'content_start' => substr($pdfContent, 0, 100),
                ]);
                throw new \Exception('Invalid PDF content received from FreeAgent');
            }

            // Generate filename
            $filename = 'invoice_'.$invoice->reference.'_'.now()->format('Y-m-d').'.pdf';

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Content-Length' => strlen($pdfContent),
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ]);

        } catch (FreeAgentOAuthException $e) {
            Log::error('FreeAgent PDF download - OAuth error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Connection Required')
                ->body('Please connect your FreeAgent account to download invoices')
                ->danger()
                ->send();

            return redirect()->back();

        } catch (FreeAgentApiException $e) {
            Log::error('FreeAgent PDF download failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Download Failed')
                ->body('Unable to download invoice PDF. Please try again later.')
                ->danger()
                ->send();

            return redirect()->back();

        } catch (\Exception $e) {
            Log::error('FreeAgent PDF download unexpected error', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            Notification::make()
                ->title('Download Failed')
                ->body('An error occurred while downloading the invoice PDF.')
                ->danger()
                ->send();

            return redirect()->back();
        }
    }
}
