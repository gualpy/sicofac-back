<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyMembershipRole;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteCompanyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');

        return $company !== null && (bool) $this->user()?->can('manageUsers', $company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([CompanyMembershipRole::Admin->value, CompanyMembershipRole::Seller->value])],
        ];
    }
}
