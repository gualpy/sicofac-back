<?php

namespace App\Http\Requests\Product;

use App\Enums\CompanyMembershipRole;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ExportProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');

        if (! $company || ! $this->user()) {
            return false;
        }

        return $this->user()->hasCompanyRole($company->id, [
                CompanyMembershipRole::Owner->value,
                CompanyMembershipRole::Admin->value,
            ])
            && (bool) $this->user()->can('export', [Product::class, $company]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
