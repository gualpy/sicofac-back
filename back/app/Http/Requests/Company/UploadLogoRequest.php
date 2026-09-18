<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UploadLogoRequest extends FormRequest
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
            'logo' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png',
                'max:2048',
            ],
        ];
    }
}
