<?php

namespace App\Http\Requests\Company;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyMemberRequest extends FormRequest
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
            'role' => ['sometimes', Rule::in(array_column(CompanyMembershipRole::cases(), 'value'))],
            'status' => ['sometimes', Rule::in(array_column(CompanyMembershipStatus::cases(), 'value'))],
        ];
    }
}

