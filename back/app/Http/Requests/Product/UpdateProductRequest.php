<?php

namespace App\Http\Requests\Product;

use App\Enums\TaxCode;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        return $product !== null && (bool) $this->user()?->can('update', $product);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');
        /** @var Product $product */
        $product = $this->route('product');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'code')
                    ->ignore($product->id)
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'auxiliary_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'tax_code' => ['sometimes', 'nullable', Rule::in(array_column(TaxCode::cases(), 'value'))],
            'ice_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'ice_code' => [
                Rule::requiredIf(fn () => (float) $this->input('ice_rate', $product->ice_rate) > 0),
                'nullable',
                'regex:/^\d{4}$/',
            ],
            'is_active' => ['sometimes', 'boolean'],
            'pos_enabled' => ['sometimes', 'boolean'],
            'pos_category_id' => [
                'sometimes',
                'nullable',
                Rule::exists('pos_categories', 'id')->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'pos_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'barcode' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')
                    ->ignore($product->id)
                    ->where(fn ($query) => $query->where('company_id', $company->id)),
            ],
            'pos_sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
