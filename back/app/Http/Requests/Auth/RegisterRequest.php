<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('web')->guest();
    }

    /**
     * Registration creates the user's account and their single company
     * (the "facturador") in one step. Self-service company creation does
     * not exist anywhere else in the API.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'device_name' => ['nullable', 'string', 'max:100'],

            'company_name' => ['required', 'string', 'max:255'],
            'company_ruc' => ['required', 'string', 'size:13', 'unique:companies,ruc'],
            'company_environment' => ['required', Rule::in(['test', 'production'])],
        ];
    }
}
