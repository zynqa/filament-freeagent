<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Zynqa\FilamentFreeAgent\Models\FreeAgentContact;
use Zynqa\FilamentFreeAgent\Models\FreeAgentOAuthToken;

trait HasFreeAgentContact
{
    /**
     * Get the FreeAgent contact associated with this user
     */
    public function freeAgentContact(): HasOne
    {
        return $this->hasOne(FreeAgentContact::class, 'id', 'freeagent_contact_id');
    }

    /**
     * Get the FreeAgent OAuth token for this user
     */
    public function freeAgentOAuthToken(): HasOne
    {
        return $this->hasOne(FreeAgentOAuthToken::class, 'user_id');
    }

    /**
     * Check if user has a FreeAgent contact assigned
     */
    public function hasFreeAgentContact(): bool
    {
        return ! empty($this->freeagent_contact_id);
    }

    /**
     * Check if user has a valid FreeAgent OAuth token
     */
    public function hasFreeAgentConnection(): bool
    {
        return $this->freeAgentOAuthToken()
            ->valid()
            ->exists();
    }

    /**
     * Get the FreeAgent contact ID for this user
     * Returns the FreeAgent API URL (e.g., https://api.freeagent.com/v2/contacts/123)
     */
    public function getFreeAgentContactId(): ?string
    {
        if (! $this->freeagent_contact_id) {
            return null;
        }

        // Load the relationship to get the FreeAgent API URL
        return $this->freeAgentContact?->freeagent_id;
    }
}
