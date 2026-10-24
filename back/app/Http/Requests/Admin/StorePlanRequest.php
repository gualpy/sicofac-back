<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by the 'super_admin' route middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:plans,slug'],
            'name' => ['required', 'string', 'max:100'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'monthly_document_limit' => ['nullable', 'integer', 'min:1'],
            'max_establishments' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
