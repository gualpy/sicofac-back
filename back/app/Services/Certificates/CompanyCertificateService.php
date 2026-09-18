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
                'subject' => $pemInfo['subject'],
                'holder_ruc' => $pemInfo['holder_ruc'],
                'holder_name' => $pemInfo['holder_name'],
                'holder_phone' => $pemInfo['holder_phone'],
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
                ...$this->issuerFieldsFromCertificate($company, $target),
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
     * Ecuadorian personal certificates carry the holder's own RUC, name and
     * phone (see validateAndParse()'s OID extraction below); once such a
     * certificate becomes the company's active signing certificate, reuse
     * that data to keep the issuer profile in sync instead of asking the
     * user to retype what the SRI already trusts. Fields the certificate
     * doesn't provide (not every CA embeds these OIDs) are left untouched,
     * and a RUC already claimed by another company is skipped rather than
     * failing the whole activation.
     *
     * @return array<string, string>
     */
    private function issuerFieldsFromCertificate(Company $company, CompanyCertificate $certificate): array
    {
        $updates = [];

        if ($certificate->holder_ruc
            && $certificate->holder_ruc !== $company->ruc
            && ! Company::query()->where('ruc', $certificate->holder_ruc)->where('id', '!=', $company->id)->exists()
        ) {
            $updates['ruc'] = $certificate->holder_ruc;
        }

        if ($certificate->holder_name) {
            $updates['name'] = $certificate->holder_name;
        }

        if ($certificate->holder_phone) {
            $updates['phone'] = $certificate->holder_phone;
        }

        return $updates;
    }

    /**
     * @return array{expires_at: Carbon|null, subject: string|null, holder_ruc: string|null, holder_name: string|null, holder_phone: string|null}
     */
    private function validateAndParse(string $filePath, string $password): array
    {
        if (! function_exists('openssl_pkcs12_read')) {
            throw new RuntimeException('OpenSSL extension is required to process PKCS12 certificates.');
        }

        if (! is_readable($filePath)) {
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

        $certificates = Pkcs12Reader::read($filePath, $password);

        $expiresAt = null;
        $subject = null;
        $holderRuc = null;
        $holderName = null;
        $holderPhone = null;

        if (! empty($certificates['cert'])) {
            $parsed = openssl_x509_parse($certificates['cert']);
            if (is_array($parsed) && isset($parsed['validTo_time_t'])) {
                $expiresAt = Carbon::createFromTimestamp((int) $parsed['validTo_time_t']);
            }
            if (is_array($parsed) && ! empty($parsed['subject']) && is_array($parsed['subject'])) {
                $subject = collect($parsed['subject'])
                    ->map(fn ($value, $key) => strtoupper((string) $key).'='.$value)
                    ->implode(', ');
            }

            if (is_array($parsed) && ! empty($parsed['extensions']) && is_array($parsed['extensions'])) {
                [$holderRuc, $holderName, $holderPhone] = $this->extractHolderIdentity($parsed['extensions']);
            }
        }

        return [
            'expires_at' => $expiresAt,
            'subject' => $subject,
            'holder_ruc' => $holderRuc,
            'holder_name' => $holderName,
            'holder_phone' => $holderPhone,
        ];
    }

    /**
     * Ecuadorian CAs (Security Data, Banco Central, and resellers under the
     * same scheme like the one seen here) embed the certificate holder's
     * identity as custom X.509 extensions under OID 1.3.6.1.4.1.59382.3.*:
     * .1 cedula, .2 nombres, .3/.4 apellidos, .8 telefono, .11 RUC. Values
     * come back from openssl_x509_parse() as raw DER (a 1-byte tag + 1-byte
     * length + the string), not decoded -- these are all short enough for a
     * single-byte length, so a plain substr() after those two bytes is
     * enough to recover the value. Certificates without these extensions
     * (foreign CAs, generic test certs) simply yield nulls throughout.
     *
     * @param  array<string, string>  $extensions
     * @return array{0: string|null, 1: string|null, 2: string|null} [ruc, name, phone]
     */
    private function extractHolderIdentity(array $extensions): array
    {
        $decode = function (?string $raw): ?string {
            if ($raw === null || strlen($raw) < 2) {
                return null;
            }

            return substr($raw, 2, ord($raw[1])) ?: null;
        };

        $ruc = $decode($extensions['1.3.6.1.4.1.59382.3.11'] ?? null);
        $ruc = ($ruc && preg_match('/^\d{13}$/', $ruc)) ? $ruc : null;

        $name = trim(implode(' ', array_filter([
            $decode($extensions['1.3.6.1.4.1.59382.3.2'] ?? null),
            $decode($extensions['1.3.6.1.4.1.59382.3.3'] ?? null),
            $decode($extensions['1.3.6.1.4.1.59382.3.4'] ?? null),
        ]))) ?: null;

        $phone = $decode($extensions['1.3.6.1.4.1.59382.3.8'] ?? null);

        return [$ruc, $name, $phone];
    }
}

