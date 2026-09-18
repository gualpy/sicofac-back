<?php

namespace App\Http\Requests\Company;

use App\Models\CompanyEmissionPoint;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmissionPointRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CompanyEmissionPoint|null $emissionPoint */
        $emissionPoint = $this->route('emissionPoint') ?? $this->route('emission_point');

        return $emissionPoint !== null
            && (bool) $this->user()?->can('manageIssuerConfig', $emissionPoint->establishment->company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
