<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\TenantStaff;
use App\Models\User;

class InvoiceAuthorizationService
{
    public function isPlatformAdmin(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $user->loadMissing('user_role');
        $name = strtoupper((string) ($user->user_role?->roleName ?? ''));

        return in_array($name, ['ADMIN', 'SUPER_ADMIN'], true);
    }

    public function isTenantSupervisor(User $user, int|string $tenantId): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        $tenant = Tenant::where('tenantId', $tenantId)->first();
        if (!$tenant) {
            return false;
        }

        if ((int) $tenant->ownerId === (int) $user->id) {
            return true;
        }

        $staff = TenantStaff::where('tenantId', $tenantId)
            ->where('userId', $user->id)
            ->first();

        if (!$staff) {
            return false;
        }

        $role = strtolower((string) ($staff->role ?? 'member'));

        return $role === 'manager';
    }

    /**
     * Invoice belongs to tenant header and user may access (creator or supervisor).
     */
    public function canAccessTenantInvoice(User $user, Invoice $invoice, ?string $tenantIdHeader): bool
    {
        if (!$tenantIdHeader || (string) $invoice->tenantId !== (string) $tenantIdHeader) {
            return false;
        }

        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        if ((int) $invoice->createdBy === (int) $user->id) {
            return true;
        }

        return $this->isTenantSupervisor($user, $invoice->tenantId);
    }

    public function canVoidInvoice(User $user, Invoice $invoice, ?string $tenantIdHeader): bool
    {
        if (!$this->canAccessTenantInvoice($user, $invoice, $tenantIdHeader)) {
            return false;
        }

        if ($invoice->voided_at || strtoupper((string) $invoice->status) === 'VOID') {
            return false;
        }

        $status = strtoupper((string) $invoice->status);

        return in_array($status, ['UNPAID', 'OVERDUE'], true);
    }

    /**
     * Amend line items for unpaid/overdue invoices only (not void, not paid flows).
     */
    public function canAmendInvoiceItems(User $user, Invoice $invoice, ?string $tenantIdHeader): bool
    {
        if (!$this->canAccessTenantInvoice($user, $invoice, $tenantIdHeader)) {
            return false;
        }

        if ($invoice->voided_at || strtoupper((string) $invoice->status) === 'VOID') {
            return false;
        }

        $status = strtoupper((string) $invoice->status);

        return in_array($status, ['UNPAID', 'OVERDUE'], true);
    }

    /**
     * When the tenant has an owner on file, void and line-item edits always require an OTP
     * emailed to that owner — including when the person acting is the owner (code goes to their inbox).
     */
    public function requiresBusinessOwnerOtp(User $user, Tenant $tenant): bool
    {
        return (bool) $tenant->ownerId;
    }

    /**
     * Void/amend cannot use the OTP flow without a tenant owner to receive the code.
     */
    public function tenantHasOwnerForSupervisoryOtp(Tenant $tenant): bool
    {
        return (bool) $tenant->ownerId;
    }

    public function isTenantOwner(User $user, Tenant $tenant): bool
    {
        if (! $tenant->ownerId) {
            return false;
        }

        return (int) $user->id === (int) $tenant->ownerId;
    }
}

