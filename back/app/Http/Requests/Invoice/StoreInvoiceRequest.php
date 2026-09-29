<?php

namespace App\Http\Requests\Invoice;

use App\Enums\CompanyMembershipRole;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTermUnit;
use App\Enums\TaxCode;
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
            'establishment_code' => [
                'sometimes', 'string', 'size:3',
                Rule::exists('company_establishments', 'code')->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'emission_point' => ['sometimes', 'string', 'size:3'],
            'guide_number' => ['nullable', 'string', 'max:17'],
            'is_negotiable' => ['sometimes', 'boolean'],
            'has_tip' => ['sometimes', 'boolean'],

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
            'items.*.tax_code' => ['nullable', Rule::in(array_column(TaxCode::cases(), 'value'))],
            'items.*.ice_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.ice_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],

            'payment_methods' => ['sometimes', 'array'],
            'payment_methods.*.method' => ['required_with:payment_methods', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payment_methods.*.value' => ['required_with:payment_methods', 'numeric', 'min:0'],
            'payment_methods.*.term_value' => ['nullable', 'integer', 'min:1'],
            'payment_methods.*.term_unit' => ['nullable', Rule::in(array_column(PaymentTermUnit::cases(), 'value'))],

            'additional_fields' => ['sometimes', 'array'],
            'additional_fields.*.name' => ['required_with:additional_fields', 'string', 'max:300'],
            'additional_fields.*.description' => ['required_with:additional_fields', 'string', 'max:300'],
        ];
    }
}
