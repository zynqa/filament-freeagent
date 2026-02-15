<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Policies;

use Zynqa\FilamentFreeAgent\Models\FreeAgentInvoice;

class FreeAgentInvoicePolicy
{
    /**
     * Determine whether the user can view any invoices.
     */
    public function viewAny($user): bool
    {
        // Admin can view all
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Regular users must have contact linked
        if (! method_exists($user, 'getFreeAgentContactId')) {
            return false;
        }

        $contactId = $user->getFreeAgentContactId();

        return (bool) $contactId; // Contact must be set
    }

    /**
     * Determine whether the user can view the invoice.
     */
    public function view($user, FreeAgentInvoice $invoice): bool
    {
        // Admin can view all
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Check method exists
        if (! method_exists($user, 'getFreeAgentContactId')) {
            return false;
        }

        $contactId = $user->getFreeAgentContactId();

        // Match contact
        return $invoice->contact_freeagent_id === $contactId;
    }

    /**
     * Determine whether the user can create invoices.
     * FreeAgent invoices are view-only - no creation allowed
     */
    public function create($user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the invoice.
     * FreeAgent invoices are view-only - no updates allowed
     */
    public function update($user, FreeAgentInvoice $invoice): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the invoice.
     * FreeAgent invoices are view-only - no deletion allowed
     */
    public function delete($user, FreeAgentInvoice $invoice): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the invoice.
     */
    public function restore($user, FreeAgentInvoice $invoice): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the invoice.
     */
    public function forceDelete($user, FreeAgentInvoice $invoice): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete any invoices.
     */
    public function deleteAny($user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can force delete any invoices.
     */
    public function forceDeleteAny($user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore any invoices.
     */
    public function restoreAny($user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can download the invoice PDF.
     */
    public function downloadPdf($user, FreeAgentInvoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }
}
