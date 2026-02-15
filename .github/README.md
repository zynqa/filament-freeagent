# FreeAgent Integration for FilamentPHP

A comprehensive Laravel/FilamentPHP package that integrates FreeAgent accounting software into Filament applications. View and download FreeAgent invoices directly from your Filament admin panel with per-user OAuth authentication.

## Features

- **OAuth 2.0 Authentication**: Secure per-user OAuth connection to FreeAgent
- **Invoice Management**: View all invoices with comprehensive filtering and search
- **PDF Downloads**: Download invoice PDFs directly from Filament
- **Auto-sync**: Automatic cache refresh when data becomes stale (30 min TTL)
- **Multi-tenancy Support**: Admin sees all invoices, users see only their contact's invoices
- **Comprehensive Caching**: Intelligent caching with automatic token refresh
- **Filament-First Design**: Native Filament Resources, Actions, Notifications
- **Policy-Based Authorization**: Granular permission control via Laravel Policies
- **Settings Integration**: Embeddable settings form for existing settings pages

## Requirements

- PHP 8.2+
- Laravel 11+
- FilamentPHP 3.2+
- FreeAgent account (Production or Sandbox)

## Installation

### 1. Install via Composer

```bash
composer require zynqa/filament-freeagent
```

### 2. Publish and Run Migrations

```bash
php artisan vendor:publish --tag="filament-freeagent-migrations"
php artisan migrate
```

### 3. Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag="filament-freeagent-config"
```

### 4. Add Trait to User Model

Add the `HasFreeAgentContact` trait to your User model:

```php
use Zynqa\FilamentFreeAgent\Models\Concerns\HasFreeAgentContact;

class User extends Authenticatable
{
    use HasFreeAgentContact;

    // ... rest of your model
}
```

### 5. Register Plugin in Panel

In your Filament Panel provider (typically `app/Providers/Filament/AppPanelProvider.php`):

```php
use Zynqa\FilamentFreeAgent\FilamentFreeAgentPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ... other configuration
        ->plugins([
            FilamentFreeAgentPlugin::make(),
        ]);
}
```

## Configuration

### Environment Variables

Add these to your `.env` file:

```env
# FreeAgent Environment (production or sandbox)
FREEAGENT_ENV=sandbox

# OAuth Credentials (get from FreeAgent Developer Dashboard)
FREEAGENT_CLIENT_ID=your_client_id
FREEAGENT_CLIENT_SECRET=your_client_secret
FREEAGENT_REDIRECT_URI=https://yourapp.com/freeagent/callback

# Cache TTL (optional)
FREEAGENT_CACHE_INVOICES=1800  # 30 minutes
FREEAGENT_CACHE_CONTACTS=3600  # 1 hour
```

### FreeAgent OAuth App Setup

1. Log into your FreeAgent account
2. Go to Settings > Developer Dashboard
3. Create a new OAuth application:
   - **Name**: Your Application Name
   - **Redirect URI**: `https://yourapp.com/freeagent/callback`
4. Copy the Client ID and Client Secret to your `.env` file

## Usage

### For Administrators

#### Embed Settings in General Settings Page

Add FreeAgent settings to your existing settings page:

```php
use Zynqa\FilamentFreeAgent\Filament\Forms\FreeAgentSettingsForm;

public static function form(Form $form): Form
{
    return $form
        ->schema([
            Tabs::make('Settings')
                ->tabs([
                    // ... other tabs

                    Tabs\Tab::make('FreeAgent')
                        ->icon('heroicon-o-document-text')
                        ->schema(FreeAgentSettingsForm::getSchema()),
                ])
        ]);
}
```

#### Configure OAuth Credentials

1. Navigate to Settings > FreeAgent tab
2. Enter your Client ID and Client Secret
3. Note the Redirect URI for FreeAgent app configuration

#### Assign FreeAgent Contacts to Users

1. Navigate to Users > Edit User
2. In the user form, assign the FreeAgent Contact ID
3. Users will only see invoices for their assigned contact

### For Users

#### Connect FreeAgent Account

1. Navigate to FreeAgent Invoices
2. Click "Connect FreeAgent"
3. Authorize access in the FreeAgent OAuth flow
4. You'll be redirected back to the application

#### View Invoices

- Navigate to FreeAgent Invoices in the sidebar
- Use filters to find specific invoices
- Click "View" to see invoice details
- Click "Download PDF" to download invoice

#### Disconnect Account

1. Go to Settings > FreeAgent tab
2. Click "Disconnect FreeAgent"
3. Confirm disconnection

## Authorization & Multi-Tenancy

### Admin Access

- Super admins see ALL invoices regardless of contact
- Can configure OAuth credentials
- Can clear all users' caches

### User Access

- Regular users only see invoices for their assigned contact
- Must have `freeagent_contact_id` set in users table
- Must connect their FreeAgent account via OAuth

### Policy Implementation

The package includes a comprehensive policy:

```php
// Admin can view all
auth()->user()->can('viewAny', FreeAgentInvoice::class);

// User can view only their contact's invoices
auth()->user()->can('view', $invoice);

// All users with access can download PDFs
auth()->user()->can('downloadPdf', $invoice);

// No create/update/delete (read-only)
auth()->user()->cannot('create', FreeAgentInvoice::class);
```

## Caching & Sync Strategy

### Automatic On-Access Sync

When a user visits the invoices page:
1. Check if cache is stale (>30 minutes old)
2. If stale, automatically sync in background
3. Display fresh data to user

### Manual Sync

Users can click "Sync Invoices" to force a refresh:
- Fetches latest invoice data
- Updates local database
- Shows sync statistics (created/updated counts)

### Cache Clearing

**Per-User Cache:**
- Settings > FreeAgent > Clear My Cache

**System-Wide Cache (Admin only):**
- Settings > FreeAgent > Clear All Users Cache

## API Services

### FreeAgentService

Core service for FreeAgent API interactions:

```php
$service = app(FreeAgentService::class);

// Get invoices
$invoices = $service->getInvoices($user, [
    'contact' => 'https://api.freeagent.com/v2/contacts/123',
    'view' => 'recent',
    'from_date' => '2024-01-01',
    'to_date' => '2024-12-31',
]);

// Get single invoice
$invoice = $service->getInvoice($user, $invoiceId);

// Download PDF
$pdfContent = $service->getInvoicePdf($user, $invoiceId);
```

### FreeAgentOAuthService

OAuth token management:

```php
$oauthService = app(FreeAgentOAuthService::class);

// Get authorization URL
$url = $oauthService->getAuthorizationUrl($state);

// Handle callback
$token = $oauthService->handleCallback($code, $user);

// Get valid token (auto-refreshes if expired)
$token = $oauthService->getValidAccessToken($user);

// Revoke token
$oauthService->revokeToken($user);
```

### FreeAgentCacheService

Sync and cache management:

```php
$cacheService = app(FreeAgentCacheService::class);

// Check if cache is stale
if ($cacheService->isInvoicesCacheStale($userId)) {
    // Sync invoices
    $stats = $cacheService->syncInvoices($user);
}

// Sync contacts
$stats = $cacheService->syncContacts($user);

// Clear user cache
$cacheService->clearUserCache($userId);
```

## Database Structure

### Tables

**freeagent_oauth_tokens**
- Stores per-user OAuth access/refresh tokens
- Auto-expires based on FreeAgent token TTL

**freeagent_contacts**
- Cached contact data from FreeAgent
- Links to users table via `freeagent_contact_id`

**freeagent_invoices**
- Cached invoice data from FreeAgent
- Linked to contacts table

**users** (modified)
- Added `freeagent_contact_id` column

## Error Handling

### OAuth Errors

- Token expired: Automatically refreshes token
- No token: Prompts user to connect FreeAgent
- Refresh failed: Deletes token, requires reconnection

### API Errors

- Rate limit exceeded: Returns 429 error with notification
- Network errors: Retries with exponential backoff
- Authentication failed: Prompts OAuth reconnection

### Logging

All errors are logged with context:

```php
Log::error('FreeAgent API error', [
    'user_id' => $userId,
    'error' => $exception->getMessage(),
    'invoice_id' => $invoiceId,
]);
```

## Testing

```bash
composer test
```

## Security

- OAuth tokens stored encrypted in database
- CSRF protection on OAuth flow via state parameter
- Policy-based authorization on all actions
- Rate limiting on API requests
- Hidden sensitive fields in model serialization

## Roadmap

- [ ] Add webhook support for real-time invoice updates
- [ ] Support for expenses and timesheets
- [ ] Bulk invoice actions
- [ ] Advanced filtering and reporting
- [ ] Export to CSV/Excel
- [ ] Multi-currency support enhancements

## Support

For issues, feature requests, or questions:
- GitHub Issues: https://github.com/zynqa/filament-freeagent/issues
- Email: info@zynqa.com

## License

MIT License - see LICENSE file for details

## Credits

- Built by [Zynqa](https://zynqa.com)
- Powered by [FilamentPHP](https://filamentphp.com)
- Integrates with [FreeAgent](https://www.freeagent.com)
