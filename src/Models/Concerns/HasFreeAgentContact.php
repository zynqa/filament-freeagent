<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Zynqa\FilamentFreeAgent\Models\FreeAgentContact;
use Zynqa\FilamentFreeAgent\Models\FreeAgentOAuthToken;

/**
 * Links a host model to a FreeAgent contact.
 *
 * Apply this trait to whichever model represents the billable entity in the
 * host application. It only requires a nullable `freeagent_contact_id` column
 * on the model's table — it is NOT specific to the User model (e.g. a property
 * management app may apply it to a Property/Household model).
 */
trait HasFreeAgentContact
{
    /**
     * The FreeAgent contact linked to this record.
     */
    public function freeAgentContact(): HasOne
    {
        return $this->hasOne(FreeAgentContact::class, 'id', 'freeagent_contact_id');
    }

    /**
     * Whether this record has a FreeAgent contact linked.
     */
    public function hasFreeAgentContact(): bool
    {
        return ! empty($this->freeagent_contact_id);
    }

    /**
     * Whether an app-wide FreeAgent connection (system OAuth token) exists.
     *
     * The OAuth connection is system-wide (a single token for the whole app),
     * not per-record, so this does not depend on the model it is called on.
     */
    public function hasFreeAgentConnection(): bool
    {
        return FreeAgentOAuthToken::system()
            ->valid()
            ->exists();
    }

    /**
     * The linked FreeAgent contact ID, as the FreeAgent API URL
     * (e.g. https://api.freeagent.com/v2/contacts/123), or null if unlinked.
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
