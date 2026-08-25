<?php

namespace App\Policies;

use App\Enums\CompanyMembershipRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CustomerPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Customer $customer): bool
    {
        return $this->belongsToCompany($user, $customer->company_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Customer $customer): bool
    {
        return $this->hasCompanyRole($user, $customer->company_id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ]);
    }

    public function delete(?User $user, Customer $customer): Response
    {
        if (! $this->hasCompanyRole($user, $customer->company_id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ])) {
            return Response::deny('You do not have permission to delete this customer.');
        }

        if ($customer->invoices()->withTrashed()->exists()) {
            return Response::deny('Cannot delete customer because it is referenced by invoices.');
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
