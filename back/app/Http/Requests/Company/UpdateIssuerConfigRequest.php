<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIssuerConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');

        return $company !== null && (bool) $this->user()?->can('manageIssuerConfig', $company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The SRI's own XSD requires the issuer RUC to be the base
            // taxpayer identity, always suffixed "001" (Anexo 14 /
            // numeroRuc), regardless of which establishment/punto de
            // emision actually issues the document.
            'ruc' => ['sometimes', 'required', 'string', 'regex:/^\d{10}001$/'],
            'establishment_code' => ['sometimes', 'required', 'string', 'size:3'],
            'emission_point' => ['sometimes', 'required', 'string', 'size:3'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'is_rimpe' => ['sometimes', 'boolean'],
            'is_special_taxpayer' => ['sometimes', 'boolean'],
            'is_popular_business' => ['sometimes', 'boolean'],
            'requires_accounting' => ['sometimes', 'boolean'],
        ];
    }
}

