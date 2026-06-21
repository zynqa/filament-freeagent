<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Zynqa\FilamentFreeAgent\Filament\Resources\FreeAgentInvoiceResource\Pages;
use Zynqa\FilamentFreeAgent\Models\FreeAgentInvoice;

class FreeAgentInvoiceResource extends Resource
{
    protected static ?string $model = FreeAgentInvoice::class;

    protected static ?string $slug = 'freeagent-invoices';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'FreeAgent Invoices';

    protected static ?string $modelLabel = 'FreeAgent Invoice';

    protected static ?string $pluralModelLabel = 'FreeAgent Invoices';

    //    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('contact.display_name')
                    ->label('Client & Project')
                    ->formatStateUsing(function (FreeAgentInvoice $record): string {
                        $client = $record->contact?->display_name ?? 'Unknown Client';
                        $project = $record->project?->name ?? 'No Project';

                        return "{$client} : {$project}";
                    })
                    ->searchable(['contact.organisation_name', 'contact.first_name', 'contact.last_name', 'project.name'])
                    ->sortable()
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (FreeAgentInvoice $record): string => $record->status_color)
                    ->formatStateUsing(fn (string $state, FreeAgentInvoice $record): string => $record->status_label)
                    ->sortable(),

                TextColumn::make('dated_on')
                    ->label('Invoice Date')
                    ->date(config('app.date_format', 'd/m/Y'))
                    ->sortable(),

                TextColumn::make('due_on')
                    ->label('Due Date')
                    ->date(config('app.date_format', 'd/m/Y'))
                    ->sortable()
                    ->color(fn (FreeAgentInvoice $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                TextColumn::make('total_value')
                    ->label('Total')
                    ->money(fn (FreeAgentInvoice $record): string => $record->currency)
                    ->sortable()
                    ->alignEnd(),

                IconColumn::make('is_overdue')
                    ->label('Overdue')
                    ->boolean()
                    ->getStateUsing(fn (FreeAgentInvoice $record): bool => $record->isOverdue())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'sent' => 'Sent',
                        'scheduled' => 'Scheduled',
                        'paid' => 'Paid',
                        'cancelled' => 'Cancelled',
                        'written_off' => 'Written Off',
                    ])
                    ->multiple(),

                SelectFilter::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'organisation_name')
                    ->preload()
                    ->searchable(),

                Filter::make('overdue')
                    ->label('Overdue Only')
                    ->query(fn (Builder $query): Builder => $query->overdue()),

                Filter::make('unpaid')
                    ->label('Unpaid Only')
                    ->query(fn (Builder $query): Builder => $query->unpaid()),

                Filter::make('dated_on')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From Date')
                            ->displayFormat(config('app.date_format', 'd/m/Y')),
                        DatePicker::make('to')
                            ->label('To Date')
                            ->displayFormat(config('app.date_format', 'd/m/Y')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('dated_on', '>=', $date),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('dated_on', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('download_pdf')
                    ->label('Download PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(fn (FreeAgentInvoice $record): string => route('freeagent.invoice.pdf', ['invoice' => $record->id]))
                    ->openUrlInNewTab()
                    ->visible(fn (FreeAgentInvoice $record): bool => Auth::user()?->can('downloadPdf', $record) ?? false),
            ])
            ->toolbarActions([
                // No bulk actions for read-only resource
            ])
            ->defaultSort('dated_on', 'desc')
            ->poll('60s') // Auto-refresh every 60 seconds
            ->emptyStateHeading('No Invoices Available')
            ->emptyStateDescription('Your account is not yet linked to a FreeAgent contact. Please contact your administrator to set up access.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Invoice Details')
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Invoice Reference')
                            ->copyable(),

                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (FreeAgentInvoice $record): string => $record->status_color)
                            ->formatStateUsing(fn (string $state, FreeAgentInvoice $record): string => $record->status_label),

                        TextEntry::make('project.name')
                            ->label('Project')
                            ->default('No Project Assigned')
                            ->icon('heroicon-o-briefcase'),

                        TextEntry::make('dated_on')
                            ->label('Invoice Date')
                            ->date(config('app.date_format', 'd/m/Y')),

                        TextEntry::make('due_on')
                            ->label('Due Date')
                            ->date(config('app.date_format', 'd/m/Y'))
                            ->color(fn (FreeAgentInvoice $record): string => $record->isOverdue() ? 'danger' : 'gray'),
                    ])
                    ->columns(2),

                Section::make('Contact Information')
                    ->schema([
                        TextEntry::make('contact.display_name')
                            ->label('Contact Name'),

                        TextEntry::make('contact.email')
                            ->label('Email')
                            ->copyable(),

                        TextEntry::make('contact.phone_number')
                            ->label('Phone')
                            ->copyable(),
                    ])
                    ->columns(2),

                Section::make('Financial Details')
                    ->schema([
                        TextEntry::make('net_value')
                            ->label('Net Amount')
                            ->money(fn (FreeAgentInvoice $record): string => $record->currency),

                        TextEntry::make('sales_tax_value')
                            ->label('VAT/Tax')
                            ->money(fn (FreeAgentInvoice $record): string => $record->currency),

                        TextEntry::make('total_value')
                            ->label('Total Amount')
                            ->money(fn (FreeAgentInvoice $record): string => $record->currency)
                            ->weight('bold')
                            ->size('lg'),

                        TextEntry::make('currency')
                            ->label('Currency')
                            ->badge(),
                    ])
                    ->columns(2),

                Section::make('Sync Information')
                    ->schema([
                        TextEntry::make('synced_at')
                            ->label('Last Synced')
                            ->dateTime(config('app.date_format', 'd/m/Y').' H:i:s')
                            ->since(),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFreeAgentInvoices::route('/'),
            'view' => Pages\ViewFreeAgentInvoice::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if (! $user) {
            return $query->whereRaw('1 = 0'); // Empty result
        }

        // Super admins see all invoices
        if ($user->hasRole('super_admin')) {
            return $query;
        }

        // Regular users MUST have contact linked
        if (! method_exists($user, 'getFreeAgentContactId')) {
            return $query->whereRaw('1 = 0'); // Empty if method missing
        }

        $contactId = $user->getFreeAgentContactId();

        // Contact must be set
        if (! $contactId) {
            return $query->whereRaw('1 = 0'); // Empty if not linked
        }

        // Filter by contact
        return $query->forContact($contactId);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        // Super admins can access
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Users with FreeAgent contact can access
        if (method_exists($user, 'hasFreeAgentContact')) {
            return $user->hasFreeAgentContact();
        }

        return false;
    }

    // Read-only resource
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
