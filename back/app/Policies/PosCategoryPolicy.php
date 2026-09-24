<?php

namespace App\Policies;

use App\Enums\CompanyMembershipRole;
use App\Models\PosCategory;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PosCategoryPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, PosCategory $posCategory): bool
    {
        return $this->belongsToCompany($user, $posCategory->company_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, PosCategory $posCategory): bool
    {
        return $this->hasCompanyRole($user, $posCategory->company_id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ]);
    }

    public function delete(?User $user, PosCategory $posCategory): Response
    {
        if (! $this->hasCompanyRole($user, $posCategory->company_id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ])) {
            return Response::deny('You do not have permission to delete this category.');
        }

        if ($posCategory->products()->exists()) {
            return Response::deny('Cannot delete a category that still has products assigned.');
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
