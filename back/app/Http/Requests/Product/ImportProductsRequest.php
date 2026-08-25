<?php

namespace App\Http\Requests\Product;

use App\Enums\CompanyMembershipRole;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ImportProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');

        if (! $company || ! $this->user()) {
            return false;
        }

        return $this->user()->hasCompanyRole($company->id, [
                CompanyMembershipRole::Owner->value,
                CompanyMembershipRole::Admin->value,
            ])
            && (bool) $this->user()->can('import', [Product::class, $company]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,xlsx',
                'max:10240',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $file = $this->file('file');

            if (! $file) {
                return;
            }

            $extension = strtolower((string) $file->getClientOriginalExtension());
            if ($extension !== 'xlsx') {
                return;
            }

            if (! extension_loaded('zip')) {
                $validator->errors()->add('file', 'XLSX requires PHP ext-zip. Please upload CSV.');
            }

            if (! extension_loaded('gd')) {
                $validator->errors()->add('file', 'XLSX requires PHP ext-gd in this environment. Please upload CSV.');
            }
        });
    }
}
