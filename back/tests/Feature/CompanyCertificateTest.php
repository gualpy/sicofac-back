<?php

namespace Tests\Feature;

use App\Enums\{CompanyMembershipRole, CompanyMembershipStatus};
use App\Models\{AuditLog, Company, CompanyCertificate, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_and_activate_certificate(): void
    {
        Storage::fake('local');
        [$company, $owner] = $this->makeCompanyWithRole(CompanyMembershipRole::Owner->value);
        Sanctum::actingAs($owner);

        $upload = $this->postJson("/api/companies/{$company->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated();

        $this->assertSame('pending', $upload->json('status'));
        $this->assertFalse($upload->json('is_active'));

        $version = (int) $upload->json('version');

        $this->postJson("/api/companies/{$company->id}/certificate/{$version}/activate")
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('is_active', true);
    }

    public function test_admin_and_seller_cannot_upload_or_activate_certificate(): void
    {
        Storage::fake('local');
        [$company, $owner] = $this->makeCompanyWithRole(CompanyMembershipRole::Owner->value, 'o1@example.com');
        Sanctum::actingAs($owner);
        $version = $this->postJson("/api/companies/{$company->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated()->json('version');

        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'a1@example.com',
            'password' => 'password',
        ]);
        $admin->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Admin->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);
        Sanctum::actingAs($admin);
        $this->postJson("/api/companies/{$company->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertForbidden();
        $this->postJson("/api/companies/{$company->id}/certificate/{$version}/activate")
            ->assertForbidden();

        [$company2, $seller] = $this->makeCompanyWithRole(CompanyMembershipRole::Seller->value, 's1@example.com');
        Sanctum::actingAs($seller);
        $this->postJson("/api/companies/{$company2->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertForbidden();
        $this->postJson("/api/companies/{$company2->id}/certificate/1/activate")
            ->assertForbidden();
    }

    public function test_certificate_audit_log_has_no_secrets(): void
    {
        Storage::fake('local');
        [$company, $owner] = $this->makeCompanyWithRole(CompanyMembershipRole::Owner->value);
        Sanctum::actingAs($owner);

        $this->postJson("/api/companies/{$company->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated();

        $audit = AuditLog::query()->where('action', 'certificate_uploaded')->latest('id')->firstOrFail();
        $meta = $audit->meta_json ?? [];

        $this->assertArrayNotHasKey('certificate_password', $meta);
        $this->assertArrayNotHasKey('password', $meta);
    }

    public function test_rotation_archives_v1_when_v2_is_activated_and_file_is_private(): void
    {
        Storage::fake('local');
        [$company, $owner] = $this->makeCompanyWithRole(CompanyMembershipRole::Owner->value);
        Sanctum::actingAs($owner);

        $v1 = $this->postJson("/api/companies/{$company->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated()->json('version');

        $v2 = $this->postJson("/api/companies/{$company->id}/certificate", [
            'certificate' => $this->makeCertificateFile(),
            'certificate_password' => 'pass123',
        ])->assertCreated()->json('version');

        $this->postJson("/api/companies/{$company->id}/certificate/{$v2}/activate")->assertOk();

        $certV1 = CompanyCertificate::query()->where('company_id', $company->id)->where('version', $v1)->firstOrFail();
        $certV2 = CompanyCertificate::query()->where('company_id', $company->id)->where('version', $v2)->firstOrFail();

        $this->assertSame('archived', $certV1->status);
        $this->assertNotNull($certV1->archived_at);
        $this->assertSame('active', $certV2->status);
        $this->assertTrue($certV2->is_active);

        Storage::disk('local')->assertExists($certV1->path);
        Storage::disk('local')->assertExists($certV2->path);
        $this->assertStringStartsWith("certificates/{$company->id}/cert_v", $certV1->path);
        $this->assertStringStartsWith("certificates/{$company->id}/cert_v", $certV2->path);
    }

    public function test_activation_syncs_issuer_identity_from_certificate_but_skips_a_ruc_already_taken(): void
    {
        Storage::fake('local');
        [$company, $owner] = $this->makeCompanyWithRole(CompanyMembershipRole::Owner->value);
        Sanctum::actingAs($owner);

        $takenRuc = '0919439349001';
        Company::query()->create(['name' => 'Other Co', 'ruc' => $takenRuc, 'environment' => 'test']);

        $version = $this->postJson("/api/companies/{$company->id}/certificate", [
            'certificate' => $this->makeCertificateFileWithHolderRuc($takenRuc),
            'certificate_password' => 'pass123',
        ])->assertCreated()->json('version');

        $certificate = CompanyCertificate::query()->where('company_id', $company->id)->where('version', $version)->firstOrFail();
        $this->assertSame($takenRuc, $certificate->holder_ruc);
        $this->assertSame('GUALBERTO ELIAS', $certificate->holder_name);

        $this->postJson("/api/companies/{$company->id}/certificate/{$version}/activate")->assertOk();

        $fresh = $company->fresh();
        // RUC untouched: $takenRuc already belongs to "Other Co".
        $this->assertNotSame($takenRuc, $fresh->ruc);
        // Name still syncs -- it's not the field that collided.
        $this->assertSame('GUALBERTO ELIAS', $fresh->name);
    }

    private function makeCertificateFileWithHolderRuc(string $ruc): UploadedFile
    {
        $encodeDer = fn (string $value) => sprintf(
            '0c:%s:%s',
            str_pad(dechex(strlen($value)), 2, '0', STR_PAD_LEFT),
            implode(':', array_map(fn ($c) => str_pad(dechex(ord($c)), 2, '0', STR_PAD_LEFT), str_split($value))),
        );

        $dir = sys_get_temp_dir().'/sicofac-holder-ruc-'.uniqid();
        mkdir($dir);
        $keyPath = "$dir/key.pem";
        $certPath = "$dir/cert.pem";
        $p12Path = "$dir/cert.p12";

        exec(sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes -subj "/C=EC/O=Test/CN=TEST HOLDER" -addext %s -addext %s 2>&1',
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg('1.3.6.1.4.1.59382.3.11=DER:'.$encodeDer($ruc)),
            escapeshellarg('1.3.6.1.4.1.59382.3.2=DER:'.$encodeDer('GUALBERTO ELIAS')),
        ), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'openssl req failed: '.implode("\n", $output));

        exec(sprintf(
            'openssl pkcs12 -export -out %s -inkey %s -in %s -passout pass:pass123 2>&1',
            escapeshellarg($p12Path),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
        ), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'openssl pkcs12 export failed: '.implode("\n", $output));

        return new UploadedFile($p12Path, 'cert.p12', 'application/x-pkcs12', null, true);
    }

    private function makeCompanyWithRole(string $role, string $email = 'owner@example.com'): array
    {
        $user = User::query()->create([
            'name' => 'Cert User',
            'email' => $email,
            'password' => 'password',
        ]);
        $company = Company::query()->create([
            'name' => 'Cert Company',
            'ruc' => fake()->unique()->numerify('0999999999###'),
            'environment' => 'test',
        ]);

        $user->companies()->attach($company->id, [
            'role' => $role,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return [$company, $user];
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

        return new UploadedFile(
            $tmpPath,
            'cert.p12',
            'application/x-pkcs12',
            null,
            true
        );
    }
}
