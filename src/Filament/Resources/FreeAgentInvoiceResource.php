<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Filament\Resources;

use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
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

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?string $modelLabel = 'Invoice';

    protected static ?string $pluralModelLabel = 'Invoices';

    //    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('contact.display_name')
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

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (FreeAgentInvoice $record): string => $record->status_color)
                    ->formatStateUsing(fn (string $state, FreeAgentInvoice $record): string => $record->status_label)
                    ->sortable(),

                Tables\Columns\TextColumn::make('dated_on')
                    ->label('Invoice Date')
                    ->date(config('app.date_format', 'd/m/Y'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('due_on')
                    ->label('Due Date')
                    ->date(config('app.date_format', 'd/m/Y'))
                    ->sortable()
                    ->color(fn (FreeAgentInvoice $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('total_value')
                    ->label('Total')
                    ->money(fn (FreeAgentInvoice $record): string => $record->currency)
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\IconColumn::make('is_overdue')
                    ->label('Overdue')
                    ->boolean()
                    ->getStateUsing(fn (FreeAgentInvoice $record): bool => $record->isOverdue())
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'sent' => 'Sent',
                        'scheduled' => 'Scheduled',
                        'paid' => 'Paid',
                        'cancelled' => 'Cancelled',
                        'written_off' => 'Written Off',
                    ])
                    ->multiple(),

                Tables\Filters\SelectFilter::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'organisation_name')
                    ->preload()
                    ->searchable(),

                Tables\Filters\Filter::make('overdue')
                    ->label('Overdue Only')
                    ->query(fn (Builder $query): Builder => $query->overdue()),

                Tables\Filters\Filter::make('unpaid')
                    ->label('Unpaid Only')
                    ->query(fn (Builder $query): Builder => $query->unpaid()),

                Tables\Filters\Filter::make('dated_on')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')
                            ->label('From Date')
                            ->displayFormat(config('app.date_format', 'd/m/Y')),
                        \Filament\Forms\Components\DatePicker::make('to')
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
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('download_pdf')
                    ->label('Download PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->url(fn (FreeAgentInvoice $record): string => route('freeagent.invoice.pdf', ['invoice' => $record->id]))
                    ->openUrlInNewTab()
                    ->visible(fn (FreeAgentInvoice $record): bool => Auth::user()?->can('downloadPdf', $record) ?? false),
            ])
            ->bulkActions([
                // No bulk actions for read-only resource
            ])
            ->defaultSort('dated_on', 'desc')
            ->poll('60s') // Auto-refresh every 60 seconds
            ->emptyStateHeading('No Invoices Available')
            ->emptyStateDescription('Your account is not yet linked to a FreeAgent contact. Please contact your administrator to set up access.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Invoice Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('reference')
                            ->label('Invoice Reference')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (FreeAgentInvoice $record): string => $record->status_color)
                            ->formatStateUsing(fn (string $state, FreeAgentInvoice $record): string => $record->status_label),

                        Infolists\Components\TextEntry::make('project.name')
                            ->label('Project')
                            ->default('No Project Assigned')
                            ->icon('heroicon-o-briefcase'),

                        Infolists\Components\TextEntry::make('dated_on')
                            ->label('Invoice Date')
                            ->date(config('app.date_format', 'd/m/Y')),

                        Infolists\Components\TextEntry::make('due_on')
                            ->label('Due Date')
                            ->date(config('app.date_format', 'd/m/Y'))
                            ->color(fn (FreeAgentInvoice $record): string => $record->isOverdue() ? 'danger' : 'gray'),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Contact Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('contact.display_name')
                            ->label('Contact Name'),

                        Infolists\Components\TextEntry::make('contact.email')
                            ->label('Email')
                            ->copyable(),

                        Infolists\Components\TextEntry::make('contact.phone_number')
                            ->label('Phone')
                            ->copyable(),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Financial Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('net_value')
                            ->label('Net Amount')
                            ->money(fn (FreeAgentInvoice $record): string => $record->currency),

                        Infolists\Components\TextEntry::make('sales_tax_value')
                            ->label('VAT/Tax')
                            ->money(fn (FreeAgentInvoice $record): string => $record->currency),

                        Infolists\Components\TextEntry::make('total_value')
                            ->label('Total Amount')
                            ->money(fn (FreeAgentInvoice $record): string => $record->currency)
                            ->weight('bold')
                            ->size('lg'),

                        Infolists\Components\TextEntry::make('currency')
                            ->label('Currency')
                            ->badge(),
                    ])
                    ->columns(2),

                Infolists\Components\Section::make('Sync Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('synced_at')
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
