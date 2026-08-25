<?php

namespace App\Http\Requests\Customer;

use App\Enums\CompanyMembershipRole;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
            && (bool) $this->user()->can('create', \App\Models\Customer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'name' => ['required', 'string', 'max:255'],
            'identification_type' => ['required', 'string', 'max:2'],
            'identification_number' => [
                'required',
                'string',
                'max:20',
                Rule::unique('customers', 'identification_number')
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
