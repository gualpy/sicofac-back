<?php

namespace App\Policies;

use App\Enums\CompanyMembershipRole;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Company $company): bool
    {
        return $this->belongsToCompany($user, $company->id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Company $company): bool
    {
        return $this->hasCompanyRole($user, $company->id, [CompanyMembershipRole::Owner->value]);
    }

    public function delete(?User $user, Company $company): bool
    {
        return $this->hasCompanyRole($user, $company->id, [CompanyMembershipRole::Owner->value]);
    }

    public function manageUsers(?User $user, Company $company): bool
    {
        return $this->hasCompanyRole($user, $company->id, [CompanyMembershipRole::Owner->value]);
    }

    public function manageIssuerConfig(?User $user, Company $company): bool
    {
        return $this->hasCompanyRole($user, $company->id, [CompanyMembershipRole::Owner->value]);
    }

    public function manageCertificate(?User $user, Company $company): bool
    {
        return $this->hasCompanyRole($user, $company->id, [CompanyMembershipRole::Owner->value]);
    }

    public function changeEnvironment(?User $user, Company $company): bool
    {
        return $this->hasCompanyRole($user, $company->id, [CompanyMembershipRole::Owner->value]);
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
