<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Filament\Resources\FreeAgentInvoiceResource\Pages;

use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Zynqa\FilamentFreeAgent\Filament\Resources\FreeAgentInvoiceResource;

class ViewFreeAgentInvoice extends ViewRecord
{
    protected static string $resource = FreeAgentInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('download_pdf')
                ->label('Download PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->url(fn (): string => route('freeagent.invoice.pdf', ['invoice' => $this->record->id]))
                ->openUrlInNewTab()
                ->visible(fn (): bool => auth()->user()?->can('downloadPdf', $this->record) ?? false),

            Actions\Action::make('back')
                ->label('Back to List')
                ->icon('heroicon-o-arrow-left')
                ->url(fn (): string => FreeAgentInvoiceResource::getUrl('index'))
                ->color('gray'),
        ];
    }
}
