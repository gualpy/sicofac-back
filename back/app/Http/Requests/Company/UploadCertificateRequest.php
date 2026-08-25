<?php

namespace App\Http\Requests\Company;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UploadCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Company|null $company */
        $company = $this->route('company');

        return $company !== null && (bool) $this->user()?->can('manageCertificate', $company);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'certificate' => [
                'required',
                'file',
                'extensions:p12,pfx',
                'mimetypes:application/x-pkcs12,application/pkcs12,application/octet-stream',
                'max:5120',
            ],
            'certificate_password' => ['required', 'string', 'max:255'],
        ];
    }
}
