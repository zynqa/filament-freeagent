<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreeAgentProject extends Model
{
    protected $table = 'freeagent_projects';

    protected $fillable = [
        'freeagent_id',
        'contact_id',
        'contact_freeagent_id',
        'name',
        'status',
        'description',
        'starts_on',
        'ends_on',
        'budget',
        'budget_units',
        'is_ir35',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'budget' => 'decimal:2',
        'is_ir35' => 'boolean',
        'raw_data' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Get the contact this project belongs to
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(FreeAgentContact::class, 'contact_id');
    }

    /**
     * Get the invoices for this project
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(FreeAgentInvoice::class, 'project_id');
    }

    /**
     * Check if project is active
     */
    public function isActive(): bool
    {
        return $this->status === 'Active';
    }

    /**
     * Scope to get only active projects
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'Active');
    }

    /**
     * Update or create project from FreeAgent API data
     */
    public static function updateOrCreateFromApi(array $apiData): self
    {
        // Find or create the contact first
        $contact = null;
        if (isset($apiData['contact'])) {
            $contact = FreeAgentContact::where('freeagent_id', $apiData['contact'])->first();
        }

        return self::updateOrCreate(
            ['freeagent_id' => $apiData['url']],
            [
                'contact_id' => $contact?->id,
                'contact_freeagent_id' => $apiData['contact'] ?? null,
                'name' => $apiData['name'] ?? 'Unnamed Project',
                'status' => $apiData['status'] ?? null,
                'description' => $apiData['description'] ?? null,
                'starts_on' => $apiData['starts_on'] ?? null,
                'ends_on' => $apiData['ends_on'] ?? null,
                'budget' => $apiData['budget'] ?? null,
                'budget_units' => $apiData['budget_units'] ?? null,
                'is_ir35' => $apiData['is_ir35'] ?? false,
                'raw_data' => $apiData,
                'synced_at' => now(),
            ]
        );
    }
}
