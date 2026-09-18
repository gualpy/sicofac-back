<?php

namespace App\Http\Requests\Company;

use App\Models\CompanyEstablishment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmissionPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CompanyEstablishment|null $establishment */
        $establishment = $this->route('establishment');

        return $establishment !== null
            && (bool) $this->user()?->can('manageIssuerConfig', $establishment->company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var CompanyEstablishment $establishment */
        $establishment = $this->route('establishment');

        return [
            'code' => [
                'required', 'string', 'size:3',
                Rule::unique('company_emission_points', 'code')
                    ->where(fn ($query) => $query->where('company_establishment_id', $establishment->id)),
            ],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
