<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FreeAgentContact extends Model
{
    protected $table = 'freeagent_contacts';

    protected $fillable = [
        'freeagent_id',
        'organisation_name',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'contact_type',
        'is_active',
        'raw_data',
        'synced_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'raw_data' => 'array',
        'synced_at' => 'datetime',
    ];

    /**
     * Get all invoices for this contact
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(FreeAgentInvoice::class, 'contact_id');
    }

    /**
     * Get users associated with this contact
     */
    public function users()
    {
        return $this->hasMany(config('auth.providers.users.model'), 'freeagent_contact_id', 'freeagent_id');
    }

    /**
     * Get display name for the contact
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->organisation_name) {
            return $this->organisation_name;
        }

        $parts = array_filter([
            $this->first_name,
            $this->last_name,
        ]);

        return implode(' ', $parts) ?: 'Unnamed Contact';
    }

    /**
     * Scope to get only active contacts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only organisation contacts
     */
    public function scopeOrganisations($query)
    {
        return $query->where('contact_type', 'organisation');
    }

    /**
     * Scope to get only person contacts
     */
    public function scopePersons($query)
    {
        return $query->where('contact_type', 'person');
    }

    /**
     * Update or create contact from FreeAgent API data
     */
    public static function updateOrCreateFromApi(array $apiData): self
    {
        return self::updateOrCreate(
            ['freeagent_id' => $apiData['url']],
            [
                'organisation_name' => $apiData['organisation_name'] ?? null,
                'first_name' => $apiData['first_name'] ?? null,
                'last_name' => $apiData['last_name'] ?? null,
                'email' => $apiData['email'] ?? null,
                'phone_number' => $apiData['phone_number'] ?? null,
                'contact_type' => $apiData['contact_name_on_invoices'] ? 'organisation' : 'person',
                'is_active' => $apiData['status'] === 'active',
                'raw_data' => $apiData,
                'synced_at' => now(),
            ]
        );
    }
}
