<?php

namespace App\Http\Requests\PosCategory;

use App\Models\Company;
use App\Models\PosCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePosCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var PosCategory|null $posCategory */
        $posCategory = $this->route('posCategory');

        return $posCategory !== null && (bool) $this->user()?->can('update', $posCategory);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');
        /** @var PosCategory $posCategory */
        $posCategory = $this->route('posCategory');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('pos_categories', 'name')
                    ->ignore($posCategory->id)
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
