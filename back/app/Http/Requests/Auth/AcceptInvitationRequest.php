<?php

namespace App\Http\Requests\Auth;

use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AcceptInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $invitation = CompanyInvitation::query()->where('token', $this->route('token'))->first();
        $isNewUser = $invitation && ! User::query()->where('email', $invitation->email)->exists();

        return [
            'name' => [Rule::requiredIf($isNewUser), 'string', 'max:255'],
            'password' => [Rule::requiredIf($isNewUser), 'string', 'min:8'],
        ];
    }
}
