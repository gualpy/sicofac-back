<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class CreateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');
        $actor = $this->user();

        // Managing API keys is a human-owner action: an API key can never
        // mint another key for itself.
        if (! $company || ! $actor instanceof User) {
            return false;
        }

        return $actor->can('manageApiKeys', $company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
