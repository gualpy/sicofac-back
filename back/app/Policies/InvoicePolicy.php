<?php

namespace App\Policies;

use App\Enums\CompanyMembershipRole;
use App\Models\Invoice;
use App\Models\User;
use App\Enums\InvoiceStatus;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Invoice $invoice): bool
    {
        return $this->belongsToCompany($user, $invoice->company_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Invoice $invoice): Response
    {
        if (! $this->hasCompanyRole($user, $invoice->company_id, [
                CompanyMembershipRole::Owner->value,
                CompanyMembershipRole::Admin->value,
            ])) {
            return Response::deny('You do not have permission to update this invoice.');
        }

        if ($invoice->status !== InvoiceStatus::Draft) {
            return Response::deny('Only DRAFT invoices can be updated.');
        }

        return Response::allow();
    }

    public function delete(?User $user, Invoice $invoice): Response
    {
        if (! $this->hasCompanyRole($user, $invoice->company_id, [
                CompanyMembershipRole::Owner->value,
                CompanyMembershipRole::Admin->value,
            ])) {
            return Response::deny('You do not have permission to delete this invoice.');
        }

        if ($invoice->status !== InvoiceStatus::Draft) {
            return Response::deny('Only DRAFT invoices can be deleted.');
        }

        return Response::allow();
    }

    public function issue(?User $user, Invoice $invoice): Response
    {
        if (! $this->hasCompanyRole($user, $invoice->company_id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ])) {
            return Response::deny('Only owner/admin can issue invoices.');
        }

        return Response::allow();
    }

    private function belongsToCompany(?User $user, int $companyId): bool
    {
        if (! $user) {
            return false;
        }

        return $user->belongsToCompany($companyId);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function hasCompanyRole(?User $user, int $companyId, array $roles): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasCompanyRole($companyId, $roles);
    }
}
