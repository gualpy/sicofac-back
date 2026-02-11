<?php

namespace App\Policies;

use App\Enums\CompanyMembershipRole;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    public function viewAny(?User $user): bool
    {
        return $user !== null;
    }

    public function view(?User $user, Product $product): bool
    {
        return $this->belongsToCompany($user, $product->company_id);
    }

    public function create(?User $user): bool
    {
        return $user !== null;
    }

    public function update(?User $user, Product $product): bool
    {
        return $this->hasCompanyRole($user, $product->company_id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ]);
    }

    public function delete(?User $user, Product $product): Response
    {
        if (! $this->hasCompanyRole($user, $product->company_id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ])) {
            return Response::deny('You do not have permission to delete this product.');
        }

        if ($product->invoiceItems()->exists()) {
            return Response::deny('Cannot delete product because it is referenced by invoice items.');
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
