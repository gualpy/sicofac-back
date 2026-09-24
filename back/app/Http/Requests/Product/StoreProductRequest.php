<?php

namespace App\Http\Requests\Product;

use App\Enums\CompanyMembershipRole;
use App\Enums\TaxCode;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
            && (bool) $this->user()->can('create', \App\Models\Product::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'code')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'auxiliary_code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tax_code' => ['nullable', Rule::in(array_column(TaxCode::cases(), 'value'))],
            'ice_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'pos_enabled' => ['sometimes', 'boolean'],
            'pos_category_id' => [
                'nullable',
                Rule::exists('pos_categories', 'id')->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'pos_label' => ['nullable', 'string', 'max:255'],
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'pos_sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
