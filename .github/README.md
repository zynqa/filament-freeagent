# FreeAgent Integration for FilamentPHP

A comprehensive Laravel/FilamentPHP package that integrates FreeAgent accounting software into Filament applications. View and download FreeAgent invoices directly from your Filament admin panel with system-wide OAuth authentication and contact-based scoping.

## Features

- **System-Wide OAuth 2.0**: Single admin-managed OAuth connection for the entire application
- **Invoice Management**: View all invoices with comprehensive filtering and search
- **Project Integration**: Display invoices with linked FreeAgent projects
- **PDF Downloads**: Download invoice PDFs directly from Filament with proper validation
- **Auto-sync**: Automatic cache refresh when data becomes stale (30 min TTL)
- **Contact-Based Scoping**: Admin sees all invoices, users see only their contact's invoices
- **Settings Integration**: Integrated into app's General Settings (not standalone)
- **Date Format Support**: Uses application's configured date format
- **Comprehensive Caching**: Intelligent caching with automatic token refresh
- **Filament-First Design**: Native Filament Resources, Actions, Notifications
- **Policy-Based Authorization**: Granular permission control via Laravel Policies
- **Full Pagination**: Fetches all invoices from FreeAgent API (not limited to 25)

## Requirements

- PHP 8.2+
- Laravel 11+
- FilamentPHP 3.2+
- FreeAgent account (Production or Sandbox)
- Spatie Laravel Settings (for app-wide settings)

## Installation

### 1. Install via Composer

```bash
composer require zynqa/filament-freeagent
```

### 2. Publish and Run Migrations

```bash
php artisan vendor:publish --provider="Zynqa\FilamentFreeAgent\FilamentFreeAgentServiceProvider"
php artisan migrate
```

This will publish and run:
- `add_freeagent_contact_id_to_users_table` - Adds FreeAgent contact linkage to users
- `create_freeagent_oauth_tokens_table` - OAuth token storage
- `create_freeagent_contacts_table` - FreeAgent contacts cache
- `create_freeagent_invoices_table` - FreeAgent invoices cache
- `create_freeagent_projects_table` - FreeAgent projects cache
- `add_project_fields_to_freeagent_invoices_table` - Links invoices to projects

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

### 5. Add FreeAgent Settings to GeneralSettings

Add FreeAgent fields to your app's `GeneralSettings` class:

```php
// app/Settings/GeneralSettings.php
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    // ... existing fields

    public ?string $freeagent_client_id;
    public ?string $freeagent_client_secret;
    public ?string $freeagent_api_url;
    public ?string $freeagent_oauth_url;

    public static function group(): string
    {
        return 'general';
    }
}
```

### 6. Create Settings Migration

```bash
php artisan make:settings-migration CreateFreeAgentSettings
```

```php
// database/settings/xxxx_create_free_agent_settings.php
public function up(): void
{
    $this->migrator->add('general.freeagent_client_id', env('FREEAGENT_CLIENT_ID'));
    $this->migrator->add('general.freeagent_client_secret', env('FREEAGENT_CLIENT_SECRET'));
    $this->migrator->add('general.freeagent_api_url', env('FREEAGENT_API_URL', 'https://api.freeagent.com/v2'));
    $this->migrator->add('general.freeagent_oauth_url', env('FREEAGENT_OAUTH_URL', 'https://api.freeagent.com'));
}
```

Then run: `php artisan migrate`

### 7. Add FreeAgent Section to General Settings Page

In your `ManageGeneralSettings` page, add the FreeAgent section to the Integrations tab:

```php
// app/Filament/Pages/ManageGeneralSettings.php
Forms\Components\Section::make('FreeAgent Integration')
    ->description('Configure FreeAgent accounting integration settings')
    ->collapsible()
    ->schema([
        // Connection status indicator
        Forms\Components\Placeholder::make('freeagent_connection_status')
            ->label('Connection Status')
            ->content(function () {
                $hasConnection = \Zynqa\FilamentFreeAgent\Models\FreeAgentOAuthToken::query()
                    ->where('user_id', 1)
                    ->exists();
                // ... status display logic
            }),

        // Connect/Disconnect actions
        Forms\Components\Actions::make([
            Forms\Components\Actions\Action::make('connect_freeagent')
                ->label('Connect FreeAgent')
                ->url(fn (): string => route('freeagent.connect')),
            // ... disconnect action
        ]),

        // Credentials
        Forms\Components\TextInput::make('freeagent_client_id'),
        Forms\Components\TextInput::make('freeagent_client_secret'),
        Forms\Components\TextInput::make('freeagent_api_url'),
        Forms\Components\TextInput::make('freeagent_oauth_url'),
    ]),
```

## Configuration

### Option 1: UI Configuration (Recommended)

1. Navigate to **Settings → General Settings → Integrations**
2. Expand **FreeAgent Integration** section
3. Enter your OAuth credentials and API URLs
4. Click **Save**

Settings are stored in database and override `.env` values.

### Option 2: Environment Variables

Add these to your `.env` file (used as fallback if UI not configured):

```env
# FreeAgent Environment (production or sandbox)
FREEAGENT_ENV=production

# OAuth Credentials (get from FreeAgent Developer Dashboard)
FREEAGENT_CLIENT_ID=your_client_id
FREEAGENT_CLIENT_SECRET=your_client_secret

# API URLs (optional, defaults provided)
FREEAGENT_API_URL=https://api.freeagent.com/v2
FREEAGENT_OAUTH_URL=https://api.freeagent.com

# Redirect URI (auto-generated)
FREEAGENT_REDIRECT_URI=https://yourapp.com/freeagent/callback

# Cache TTL (optional)
FREEAGENT_CACHE_INVOICES=1800  # 30 minutes
FREEAGENT_CACHE_CONTACTS=3600  # 1 hour
```

**Configuration Priority:**
1. Database Settings (UI configured) ← Highest
2. Environment Variables (`.env`)
3. Default Values ← Lowest

### FreeAgent OAuth App Setup

1. Log into your FreeAgent account
2. Go to **Settings → Developer Dashboard**
3. Create a new OAuth application:
   - **Name**: Your Application Name
   - **Redirect URI**: `https://yourapp.com/freeagent/callback`
4. Copy the Client ID and Client Secret

## Usage

### For Administrators

#### 1. Configure OAuth Credentials

**Settings → General Settings → Integrations → FreeAgent Integration**

- Enter Client ID and Client Secret
- Configure API URLs (or use defaults)
- Save settings

#### 2. Connect FreeAgent (Admin-Only)

**Settings → General Settings → Integrations → FreeAgent Integration**

1. Click **"Connect FreeAgent"** button
2. Authorize access in FreeAgent OAuth flow
3. You'll be redirected back
4. Status changes to "Connected" ✅

**Important:** This creates a **system-wide connection** that all users share. Only admins can connect/disconnect.

#### 3. Assign FreeAgent Contacts to Users

**Users → Edit User → FreeAgent Tab**

1. Select the FreeAgent Contact from dropdown
2. Save
3. User will now see invoices for that contact only

**Contact Assignment:**
- **Required for regular users** to see invoices
- **Not required for super_admins** (they see all invoices)
- Users without contact linkage see empty state

#### 4. Disconnect FreeAgent (Admin-Only)

**Settings → General Settings → Integrations → FreeAgent Integration**

1. Click **"Disconnect FreeAgent"** button
2. Confirm in modal
3. **All users** lose access to FreeAgent invoices

### For Regular Users

#### View Invoices

1. Navigate to **Invoices** in sidebar
2. See invoices for your assigned contact only
3. Use filters to find specific invoices
4. Click **"View"** to see invoice details
5. Click **"Download PDF"** to download invoice

#### Sync Invoices

1. Click **"Sync Invoices"** button
2. Confirm sync
3. Latest invoices fetched from FreeAgent

**Note:** If not connected to a contact, you'll see:
> "No Invoices Available - Your account is not yet linked to a FreeAgent contact. Please contact your administrator to set up access."

## Authorization & Multi-Tenancy

### Admin Access (super_admin role)

- ✅ See **ALL invoices** regardless of contact
- ✅ Configure OAuth credentials
- ✅ Connect/disconnect FreeAgent
- ✅ Assign contacts to users
- ✅ Access FreeAgent settings

### User Access (regular users)

- ✅ See invoices **only for their assigned contact**
- ✅ Must have `freeagent_contact_id` set by admin
- ✅ Can sync invoices (uses system-wide connection)
- ✅ Can download PDFs for their invoices
- ❌ Cannot see other contacts' invoices
- ❌ Cannot connect/disconnect FreeAgent
- ❌ Cannot change their contact assignment

### Contact-Based Scoping

The package implements secure contact-based scoping:

```php
// Super admins see all
if ($user->hasRole('super_admin')) {
    return $query; // All invoices
}

// Regular users must have contact linked
$contactId = $user->getFreeAgentContactId();
if (!$contactId) {
    return $query->whereRaw('1 = 0'); // Empty
}

// Filter by contact
return $query->forContact($contactId);
```

### Policy Implementation

```php
// View any invoices (if has contact or is admin)
auth()->user()->can('viewAny', FreeAgentInvoice::class);

// View specific invoice (must match contact)
auth()->user()->can('view', $invoice);

// Download PDF (same as view)
auth()->user()->can('downloadPdf', $invoice);

// No create/update/delete (read-only resource)
auth()->user()->cannot('create', FreeAgentInvoice::class);
```

## Features in Detail

### Invoice List

**Columns:**
- **Reference** - Invoice number (searchable, sortable, copyable)
- **Client & Project** - Shows "Contact : Project" format
- **Status** - Color-coded badge (Paid=green, Sent/Open=yellow, Overdue=red, Draft=gray)
- **Invoice Date** - Uses app's configured date format
- **Due Date** - Color-coded (red if overdue)
- **Total** - Amount with currency
- **Overdue** - Boolean indicator (toggleable)

**Filters:**
- Status (multiple selection)
- Contact (searchable dropdown)
- Overdue Only
- Unpaid Only
- Date Range (from/to)

**Actions:**
- **View** - See invoice details
- **Download PDF** - Download invoice PDF
- **Sync Invoices** - Fetch latest from FreeAgent
- **FreeAgent Settings** - Go to settings (admin only)

### Invoice Detail View

**Sections:**
- **Invoice Details**: Reference, Status, Project, Dates
- **Contact Information**: Name, Email, Phone
- **Financial Details**: Net, VAT/Tax, Total, Currency
- **Sync Information**: Last synced timestamp

### Project Integration

Invoices automatically link to FreeAgent projects:
- Projects synced from FreeAgent API
- Displayed as "Contact : Project" in list
- Shown in detail view
- Searchable by project name

### Date Format Integration

All dates use the app's configured format:
- **Configure:** Settings → General Settings → General → Date Format
- **Supported:** DD/MM/YYYY, MM/DD/YYYY, YYYY-MM-DD, and more
- **Applied to:** Table columns, filters, detail view, datetime fields

### PDF Downloads

Secure PDF download with validation:
- Downloads invoice PDF from FreeAgent API
- Validates PDF magic bytes (`%PDF`)
- Proper content headers
- Authorization check before download
- Base64 decoding handled automatically

## Caching & Sync Strategy

### System-Wide Connection

The package uses a **single OAuth connection** (user_id = 1) shared by all users:
- Admin connects once in General Settings
- All users benefit from the connection
- No per-user OAuth required
- Simplified token management

### Automatic On-Access Sync

When a user visits the invoices page:
1. Check if cache is stale (>30 minutes old)
2. If stale, automatically sync in background
3. Display fresh data to user
4. No user interaction needed

### Manual Sync

Users can click **"Sync Invoices"** to force a refresh:
- Fetches latest invoices from FreeAgent
- Syncs contacts and projects
- Updates local database
- Shows sync statistics (created/updated counts)

### Pagination

All API calls use full pagination:
- Fetches 100 records per page (FreeAgent max)
- Continues until all records fetched
- No 25-record limit
- Safety limit: 1000 pages

## API Services

### FreeAgentService

Core service for FreeAgent API interactions:

```php
$service = app(FreeAgentService::class);

// Get invoices with filters
$invoices = $service->getInvoices($user, [
    'contact' => 'https://api.freeagent.com/v2/contacts/123',
    'view' => 'recent',
    'from_date' => '2024-01-01',
    'to_date' => '2024-12-31',
]);

// Get single invoice
$invoice = $service->getInvoice($user, $invoiceId);

// Download PDF (returns binary content)
$pdfContent = $service->getInvoicePdf($user, $invoiceId);

// Get contacts
$contacts = $service->getContacts($user);

// Get projects
$projects = $service->getProjects($user, [
    'contact' => 'https://api.freeagent.com/v2/contacts/123'
]);
```

### FreeAgentOAuthService

System-wide OAuth token management:

```php
$oauthService = app(FreeAgentOAuthService::class);

// Get authorization URL
$url = $oauthService->getAuthorizationUrl($state);

// Handle callback (stores with user_id = 1)
$token = $oauthService->handleCallback($code, $user);

// Get valid system token (auto-refreshes if expired)
$token = $oauthService->getValidAccessToken(); // No user param needed

// Revoke system token
$oauthService->revokeToken();
```

**Note:** All methods now use system-wide token (user_id = 1) instead of per-user tokens.

### FreeAgentCacheService

Sync and cache management:

```php
$cacheService = app(FreeAgentCacheService::class);

// Check if cache is stale
if ($cacheService->isInvoicesCacheStale($userId)) {
    // Sync invoices
    $stats = $cacheService->syncInvoices($user, $filters);
    // Returns: ['total' => 150, 'created' => 5, 'updated' => 10]
}

// Sync contacts
$stats = $cacheService->syncContacts($user);

// Sync projects
$stats = $cacheService->syncProjects($user);
```

## Database Structure

### Tables

**freeagent_oauth_tokens**
- Stores system-wide OAuth token (user_id = 1)
- Auto-refreshes when expired
- Single token for entire app

**freeagent_contacts**
- Cached contact data from FreeAgent
- Links to users table via `freeagent_contact_id`
- Includes organization name, email, phone

**freeagent_invoices**
- Cached invoice data from FreeAgent
- Linked to contacts and projects
- Includes status, dates, amounts

**freeagent_projects**
- Cached project data from FreeAgent
- Linked to contacts
- Includes name, status, dates, budget

**users** (modified)
- Added `freeagent_contact_id` column
- Links user to FreeAgent contact

### Relationships

```
User → FreeAgentContact → FreeAgentInvoice
                       → FreeAgentProject → FreeAgentInvoice
```

## Error Handling

### OAuth Errors

- **Token expired**: Automatically refreshes token
- **No token**: Shows "Not Connected" in settings
- **Refresh failed**: Deletes token, requires reconnection
- **User notifications**: Friendly error messages with action buttons

### API Errors

- **Rate limit exceeded**: Returns 429 error with notification
- **Network errors**: Retries with exponential backoff (3 attempts)
- **Authentication failed**: Prompts OAuth reconnection
- **PDF errors**: Validates content and shows clear error messages

### Logging

All errors are logged with context:

```php
Log::error('FreeAgent API error', [
    'user_id' => $userId,
    'endpoint' => $endpoint,
    'status_code' => $statusCode,
    'error' => $exception->getMessage(),
]);
```

## Security

- ✅ OAuth tokens encrypted in database
- ✅ CSRF protection on OAuth flow via state parameter
- ✅ Policy-based authorization on all actions
- ✅ Rate limiting on API requests (120/min per user)
- ✅ Hidden sensitive fields in model serialization
- ✅ Admin-only connection management
- ✅ Contact-based data isolation
- ✅ PDF content validation (magic bytes check)
- ✅ Settings stored securely with Spatie Laravel Settings

## Testing

```bash
composer test
```

## Changelog

### Recent Improvements

- ✅ System-wide OAuth connection (admin-managed)
- ✅ Project integration with invoices
- ✅ Full pagination support (no 25-record limit)
- ✅ PDF download with base64 decoding
- ✅ Date format integration with app settings
- ✅ Contact-based scoping improvements
- ✅ Settings integration into app's GeneralSettings
- ✅ Empty state for unlinked users
- ✅ Status color coding (Paid/Sent/Overdue)
- ✅ Migration organization (package stubs)

## Roadmap

- [ ] Webhook support for real-time updates
- [ ] Support for expenses and timesheets
- [ ] Bulk invoice actions
- [ ] Advanced reporting and analytics
- [ ] Export to CSV/Excel
- [ ] Multi-currency enhancements
- [ ] Invoice templates customization

## Troubleshooting

### "Cannot assign Closure to property" Error

**Solution:** Clear cache after settings updates:
```bash
php artisan config:clear
php artisan optimize:clear
```

### "No Invoices Available" Message

**Causes:**
1. Admin hasn't connected FreeAgent
2. User not linked to a FreeAgent contact
3. No invoices for user's contact

**Solution:** Contact admin to link your account to a FreeAgent contact.

### PDF Download Corrupted

This has been fixed! PDFs now properly decode from base64 and validate magic bytes.

### Invoices Not Syncing

**Check:**
1. FreeAgent connected in Settings
2. OAuth token not expired (auto-refreshes)
3. Check `storage/logs/laravel.log` for errors

## Support

For issues, feature requests, or questions:
- GitHub Issues: https://github.com/zynqa/filament-freeagent/issues
- Email: info@zynqa.com
- Documentation: See package `.md` files

## License

MIT License - see LICENSE file for details

## Credits

- Built by [Zynqa](https://zynqa.com)
- Powered by [FilamentPHP](https://filamentphp.com)
- Integrates with [FreeAgent](https://www.freeagent.com)
- Uses [Spatie Laravel Settings](https://github.com/spatie/laravel-settings)
