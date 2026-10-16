<?php

namespace App\Http\Requests\Invoice;

use App\Enums\CompanyMembershipRole;
use App\Models\Company;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Body of POST /api/v1/companies/{company}/invoices/issue. Reuses the invoice
 * rules of StoreInvoiceRequest; the buyer travels inline instead of customer_id.
 */
class IssueInvoiceRequest extends StoreInvoiceRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');
        $actor = $this->user();

        if (! $company || ! $actor) {
            return false;
        }

        // Service-to-service client authenticated with the company's own API
        // key: the key is already scoped 1-to-1 to its company, no role to check.
        if ($actor instanceof Company) {
            return $actor->is($company);
        }

        // Human user: creates AND issues in one call, so needs owner/admin.
        return $actor->hasCompanyRole($company->id, [
            CompanyMembershipRole::Owner->value,
            CompanyMembershipRole::Admin->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['customer_id']);

        return $rules + [
            'external_reference' => ['required', 'string', 'max:100'],
            'buyer' => ['nullable', 'array'],
            'buyer.identification_type' => ['required_with:buyer', 'string', 'max:2'],
            'buyer.identification_number' => ['required_with:buyer', 'string', 'max:20'],
            'buyer.name' => ['required_with:buyer', 'string', 'max:255'],
            'buyer.email' => ['nullable', 'email', 'max:255'],
            'buyer.phone' => ['nullable', 'string', 'max:30'],
            'buyer.address' => ['nullable', 'string', 'max:255'],
            'expected_totals' => ['nullable', 'array'],
            'expected_totals.subtotal' => ['nullable', 'numeric', 'min:0'],
            'expected_totals.tax' => ['nullable', 'numeric', 'min:0'],
            'expected_totals.total' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'The request body is invalid.',
                'details' => $validator->errors()->toArray(),
            ],
        ], 422));
    }
}
