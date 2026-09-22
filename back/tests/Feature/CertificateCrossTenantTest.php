<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyCertificate;
use App\Models\User;
use App\Services\Certificates\CompanyCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CertificateCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Company, 1: User}
     */
    private function makeCompanyWithOwner(string $name, string $ruc, string $email): array
    {
        $owner = User::query()->create([
            'name' => "Owner {$name}",
            'email' => $email,
            'password' => 'password',
        ]);
        $company = Company::query()->create([
            'name' => $name,
            'ruc' => $ruc,
            'environment' => 'test',
        ]);
        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return [$company, $owner];
    }

    private function makeCertificateFile(): UploadedFile
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new([
            'commonName' => 'Test Certificate',
            'organizationName' => 'Test Org',
        ], $privateKey);

        $x509 = openssl_csr_sign($csr, null, $privateKey, 365);

        $pkcs12 = '';
        openssl_pkcs12_export($x509, $pkcs12, $privateKey, 'pass123');

        $tmpPath = tempnam(sys_get_temp_dir(), 'cert_');
        file_put_contents($tmpPath, $pkcs12);

        return new UploadedFile($tmpPath, 'cert.p12', 'application/x-pkcs12', null, true);
    }

    public function test_company_a_cannot_view_certificate_of_company_b(): void
    {
        Storage::fake('local');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999301', 'b1cert@example.com');
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999302', 'a1cert@example.com');

        Sanctum::actingAs($userB);
        $this->postJson("/api/companies/{$companyB->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated();

        Sanctum::actingAs($userA);
        $this->getJson("/api/companies/{$companyB->id}/certificate")
            ->assertForbidden();
    }

    public function test_company_a_can_view_its_own_certificate(): void
    {
        Storage::fake('local');
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999303', 'a2cert@example.com');

        Sanctum::actingAs($userA);
        $version = $this->postJson("/api/companies/{$companyA->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated()->json('version');
        $this->postJson("/api/companies/{$companyA->id}/certificate/{$version}/activate")->assertOk();

        $this->getJson("/api/companies/{$companyA->id}/certificate")
            ->assertOk()
            ->assertJsonPath('version', $version);
    }

    public function test_company_a_cannot_upload_certificate_into_company_b(): void
    {
        Storage::fake('local');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999304', 'b2cert@example.com');
        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999305', 'a3cert@example.com');

        Sanctum::actingAs($userA);
        $this->postJson("/api/companies/{$companyB->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertForbidden();

        $this->assertDatabaseCount('company_certificates', 0);
    }

    public function test_company_a_cannot_activate_certificate_of_company_b(): void
    {
        Storage::fake('local');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999306', 'b3cert@example.com');
        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999307', 'a4cert@example.com');

        Sanctum::actingAs($userB);
        $version = $this->postJson("/api/companies/{$companyB->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated()->json('version');

        Sanctum::actingAs($userA);
        $this->postJson("/api/companies/{$companyB->id}/certificate/{$version}/activate")
            ->assertForbidden();

        $this->assertDatabaseHas('company_certificates', [
            'company_id' => $companyB->id,
            'version' => $version,
            'is_active' => false,
        ]);
    }

    public function test_company_a_cannot_delete_certificate_of_company_b(): void
    {
        Storage::fake('local');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999308', 'b4cert@example.com');
        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999309', 'a5cert@example.com');

        Sanctum::actingAs($userB);
        $version = $this->postJson("/api/companies/{$companyB->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated()->json('version');
        $this->postJson("/api/companies/{$companyB->id}/certificate/{$version}/activate")->assertOk();

        Sanctum::actingAs($userA);
        $this->deleteJson("/api/companies/{$companyB->id}/certificate")
            ->assertForbidden();

        $this->assertDatabaseHas('company_certificates', [
            'company_id' => $companyB->id,
            'version' => $version,
            'is_active' => true,
        ]);
    }

    /**
     * Service-level guarantee: even with two companies each holding an
     * active certificate row, resolving "the active certificate" for
     * Company A can never return Company B's row. No real cert bytes or
     * crypto involved -- this only exercises the company_id-scoped query.
     */
    public function test_get_active_certificate_never_crosses_tenants(): void
    {
        [$companyA] = $this->makeCompanyWithOwner('Company A', '0999999999310', 'a6cert@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999311', 'b5cert@example.com');

        $certA = CompanyCertificate::query()->create([
            'company_id' => $companyA->id,
            'version' => 1,
            'path' => "certificates/{$companyA->id}/cert_v1.p12",
            'status' => 'active',
            'is_active' => true,
            'password_encrypted' => 'fake-encrypted-value-a',
            'uploaded_at' => now(),
        ]);

        $certB = CompanyCertificate::query()->create([
            'company_id' => $companyB->id,
            'version' => 1,
            'path' => "certificates/{$companyB->id}/cert_v1.p12",
            'status' => 'active',
            'is_active' => true,
            'password_encrypted' => 'fake-encrypted-value-b',
            'uploaded_at' => now(),
        ]);

        $service = app(CompanyCertificateService::class);

        $resolvedForA = $service->getActiveCertificate($companyA);
        $resolvedForB = $service->getActiveCertificate($companyB);

        $this->assertNotNull($resolvedForA);
        $this->assertNotNull($resolvedForB);
        $this->assertSame($certA->id, $resolvedForA->id);
        $this->assertSame($certB->id, $resolvedForB->id);
        $this->assertNotSame($resolvedForA->id, $resolvedForB->id);
    }
}
