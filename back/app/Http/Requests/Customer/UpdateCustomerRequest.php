<?php

namespace App\Http\Requests\Customer;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Customer|null $customer */
        $customer = $this->route('customer');

        return $customer !== null && (bool) $this->user()?->can('update', $customer);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'identification_type' => ['sometimes', 'required', 'string', 'max:2'],
            'identification_number' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('customers', 'identification_number')
                    ->ignore($customer->id)
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
