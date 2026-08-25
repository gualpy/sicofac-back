<?php

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Actual authorization happens in the controller via
        // $this->authorize('create', Company::class) so the policy's deny
        // message reaches the client.
        return $this->user() !== null;
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
