<?php

namespace App\Http\Requests\Product;

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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
