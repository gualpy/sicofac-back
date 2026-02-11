<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'ruc' => ['required', 'string', 'size:13', 'unique:companies,ruc'],
            'environment' => ['required', Rule::in(['test', 'production'])],
            'sri_signing_enabled' => ['sometimes', 'boolean'],
            'sri_submission_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
