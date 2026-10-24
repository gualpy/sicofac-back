<?php

namespace App\Http\Requests\Admin;

use App\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
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
        /** @var Plan $plan */
        $plan = $this->route('plan');

        return [
            'slug' => ['sometimes', 'string', 'max:50', 'alpha_dash', Rule::unique('plans', 'slug')->ignore($plan->id)],
            'name' => ['sometimes', 'string', 'max:100'],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
            // 'sometimes' here matters: without it, an omitted field still
            // validates as null (nullable) and validated() would wipe an
            // existing limit on a partial update instead of leaving it alone.
            'monthly_document_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_establishments' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
