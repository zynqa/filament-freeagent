<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     *
     * belongsTo, not hasOne: this record holds freeagent_contact_id pointing at
     * freeagent_contacts.id, so it owns the key. The relationship was previously declared
     * as hasOne(FreeAgentContact::class, 'id', 'freeagent_contact_id'), which reads
     * identically but is the wrong way round for writes — and Filament's
     * Select::relationship() writes. Saving a user with a contact linked made Filament
     * push the key onto the *contact*, producing
     * `update freeagent_contacts set id = null where id = 3` and an integrity violation.
     */
    public function freeAgentContact(): BelongsTo
    {
        return $this->belongsTo(FreeAgentContact::class, 'freeagent_contact_id', 'id');
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
