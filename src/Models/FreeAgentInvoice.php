<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreeAgentInvoice extends Model
{
    protected $table = 'freeagent_invoices';

    protected $fillable = [
        'freeagent_id',
        'contact_id',
        'contact_freeagent_id',
        'project_id',
        'project_freeagent_id',
        'reference',
        'status',
        'dated_on',
        'due_on',
        'net_value',
        'sales_tax_value',
        'total_value',
        'currency',
        'pdf_url',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'dated_on' => 'date',
        'due_on' => 'date',
        'net_value' => 'decimal:2',
        'sales_tax_value' => 'decimal:2',
        'total_value' => 'decimal:2',
        'raw_data' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Get the contact this invoice belongs to
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(FreeAgentContact::class, 'contact_id');
    }

    /**
     * Get the project this invoice belongs to
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(FreeAgentProject::class, 'project_id');
    }

    /**
     * Get formatted total value with currency
     */
    public function getFormattedTotalAttribute(): string
    {
        return $this->formatMoney($this->total_value);
    }

    /**
     * Get formatted net value with currency
     */
    public function getFormattedNetAttribute(): string
    {
        return $this->formatMoney($this->net_value);
    }

    /**
     * Get formatted sales tax value with currency
     */
    public function getFormattedSalesTaxAttribute(): string
    {
        return $this->formatMoney($this->sales_tax_value);
    }

    /**
     * Format money value with currency symbol
     */
    private function formatMoney(float|string $amount): string
    {
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
        ];

        $symbol = $symbols[$this->currency] ?? $this->currency;

        return $symbol.number_format((float) $amount, 2);
    }

    /**
     * Get status badge color for Filament
     */
    public function getStatusColorAttribute(): string
    {
        // Overdue invoices are always red
        if ($this->isOverdue()) {
            return 'danger';
        }

        return match (strtolower($this->status)) {
            'paid' => 'success', // Green
            'sent', 'scheduled', 'open' => 'warning', // Yellow/Orange
            'cancelled', 'written_off' => 'danger', // Red
            'draft' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        // Show "Overdue" for overdue invoices
        if ($this->isOverdue()) {
            return 'Overdue';
        }

        return ucfirst(str_replace('_', ' ', $this->status));
    }

    /**
     * Check if invoice is overdue
     */
    public function isOverdue(): bool
    {
        return $this->due_on
            && $this->due_on->isPast()
            && ! in_array(strtolower($this->status), ['paid', 'cancelled', 'written_off', 'draft']);
    }

    /**
     * Scope to get only paid invoices
     */
    public function scopePaid($query)
    {
        return $query->whereRaw('LOWER(status) = ?', ['paid']);
    }

    /**
     * Scope to get only unpaid invoices
     */
    public function scopeUnpaid($query)
    {
        return $query->whereRaw('LOWER(status) NOT IN (?, ?, ?)', ['paid', 'cancelled', 'written_off']);
    }

    /**
     * Scope to get overdue invoices
     */
    public function scopeOverdue($query)
    {
        return $query->whereRaw('LOWER(status) NOT IN (?, ?, ?, ?)', ['paid', 'cancelled', 'written_off', 'draft'])
            ->where('due_on', '<', now());
    }

    /**
     * Scope to get invoices for a specific contact
     */
    public function scopeForContact($query, string $contactFreeagentId)
    {
        return $query->where('contact_freeagent_id', $contactFreeagentId);
    }

    /**
     * Scope to get invoices within date range
     */
    public function scopeBetweenDates($query, $fromDate, $toDate)
    {
        return $query->whereBetween('dated_on', [$fromDate, $toDate]);
    }

    /**
     * Update or create invoice from FreeAgent API data
     */
    public static function updateOrCreateFromApi(array $apiData): self
    {
        // Find or create the contact first
        $contact = null;
        if (isset($apiData['contact'])) {
            $contact = FreeAgentContact::where('freeagent_id', $apiData['contact'])->first();
        }

        // Find or create the project
        $project = null;
        if (isset($apiData['project'])) {
            $project = FreeAgentProject::where('freeagent_id', $apiData['project'])->first();
        }

        return self::updateOrCreate(
            ['freeagent_id' => $apiData['url']],
            [
                'contact_id' => $contact?->id,
                'contact_freeagent_id' => $apiData['contact'] ?? null,
                'project_id' => $project?->id,
                'project_freeagent_id' => $apiData['project'] ?? null,
                'reference' => $apiData['reference'] ?? null,
                'status' => $apiData['status'],
                'dated_on' => $apiData['dated_on'],
                'due_on' => $apiData['due_on'] ?? null,
                'net_value' => $apiData['net_value'],
                'sales_tax_value' => $apiData['sales_tax_value'],
                'total_value' => $apiData['total_value'],
                'currency' => $apiData['currency'],
                'pdf_url' => $apiData['url'].'/pdf',
                'raw_data' => $apiData,
                'synced_at' => now(),
            ]
        );
    }
}
