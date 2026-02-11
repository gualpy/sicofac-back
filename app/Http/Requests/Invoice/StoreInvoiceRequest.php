<?php

namespace App\Http\Requests\Invoice;

use App\Enums\CompanyMembershipRole;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
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
                CompanyMembershipRole::Seller->value,
            ])
            && (bool) $this->user()->can('create', \App\Models\Invoice::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'document_code' => ['sometimes', Rule::in(['01', '04', '05', '06', '07'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'items.*.code' => ['required', 'string', 'max:50'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
