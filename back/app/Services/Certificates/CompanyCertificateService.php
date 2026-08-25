<?php

namespace App\Services\Certificates;

use App\DTOs\Certificates\CertificateUploadData;
use App\Models\{Company, CompanyCertificate};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{Crypt, DB, Storage};
use RuntimeException;

class CompanyCertificateService
{
    public function uploadCertificate(Company $company, UploadedFile $file, string $password): CompanyCertificate
    {
        $data = new CertificateUploadData(
            companyId: $company->id,
            file: $file,
            password: $password,
        );

        return DB::transaction(function () use ($data) {
            $latest = CompanyCertificate::query()
                ->where('company_id', $data->companyId)
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            $version = ($latest?->version ?? 0) + 1;
            $path = "certificates/{$data->companyId}/cert_v{$version}.p12";
            $pemInfo = $this->validateAndParse($data->file->getRealPath(), $data->password);

            Storage::disk('local')->put($path, file_get_contents($data->file->getRealPath()));
            $absolutePath = Storage::disk('local')->path($path);
            @chmod($absolutePath, 0600);

            return CompanyCertificate::query()->create([
                'company_id' => $data->companyId,
                'version' => $version,
                'scope_type' => 'company',
                'scope_id' => null,
                'path' => $path,
                'status' => 'pending',
                'is_active' => false,
                'password_encrypted' => Crypt::encryptString($data->password),
                'uploaded_at' => now(),
                'expires_at' => $pemInfo['expires_at'],
            ]);
        });
    }

    public function activateCertificate(Company $company, int $version): CompanyCertificate
    {
        return DB::transaction(function () use ($company, $version) {
            $target = CompanyCertificate::query()
                ->where('company_id', $company->id)
                ->where('version', $version)
                ->lockForUpdate()
                ->firstOrFail();

            CompanyCertificate::query()
                ->where('company_id', $company->id)
                ->where('id', '!=', $target->id)
                ->update([
                    'is_active' => false,
                    'status' => 'archived',
                    'archived_at' => now(),
                ]);

            $target->update([
                'is_active' => true,
                'status' => 'active',
                'archived_at' => null,
            ]);

            $company->update([
                'certificate_path' => $target->path,
                'certificate_name' => basename($target->path),
                'certificate_uploaded_at' => $target->uploaded_at,
            ]);

            return $target->fresh();
        });
    }

    public function getActiveCertificate(Company $company): ?CompanyCertificate
    {
        return CompanyCertificate::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @return array{expires_at: Carbon|null}
     */
    private function validateAndParse(string $filePath, string $password): array
    {
        if (! function_exists('openssl_pkcs12_read')) {
            throw new RuntimeException('OpenSSL extension is required to process PKCS12 certificates.');
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException('Unable to read certificate file.');
        }

        $mime = @mime_content_type($filePath);
        $allowedMimes = [
            'application/x-pkcs12',
            'application/pkcs12',
            'application/octet-stream',
        ];
        if ($mime !== false && ! in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('Invalid certificate MIME type.');
        }

        $certificates = [];
        if (! openssl_pkcs12_read($content, $certificates, $password)) {
            throw new RuntimeException('Invalid PKCS12 certificate or password.');
        }

        $expiresAt = null;
        if (! empty($certificates['cert'])) {
            $parsed = openssl_x509_parse($certificates['cert']);
            if (is_array($parsed) && isset($parsed['validTo_time_t'])) {
                $expiresAt = Carbon::createFromTimestamp((int) $parsed['validTo_time_t']);
            }
        }

        return ['expires_at' => $expiresAt];
    }
}

