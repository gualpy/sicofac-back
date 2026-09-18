<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\CompanyEstablishment;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Certificates\CompanyCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke test tying together all three "real" SRI pieces built so far
 * (RealXmlBuilder, RealXadesSigner, RealSriClient) through the actual
 * BuildXmlJob -> SignXadesJob -> SendReceptionJob -> CheckAuthorizationJob
 * chain, instead of testing each in isolation. Runs against the same local
 * mock SOAP server used by RealSriClientTest -- it does not touch the real
 * SRI, so it proves the pieces interoperate, not that the SRI itself
 * accepts the output.
 */
class RealPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private $serverProcess;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        $docroot = __DIR__.'/../Fixtures/sri-mock';
        $this->serverProcess = proc_open(
            ['php', '-S', "127.0.0.1:{$this->port}", '-t', $docroot, $docroot.'/router.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($connection) {
                fclose($connection);
                break;
            }
            usleep(50_000);
        }

        config([
            'sri.driver' => 'real',
            'sri.wsdl.reception.test' => "http://127.0.0.1:{$this->port}/router.php?wsdl",
            'sri.wsdl.authorization.test' => "http://127.0.0.1:{$this->port}/router.php?wsdl",
        ]);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        parent::tearDown();
    }

    public function test_full_real_pipeline_authorizes_an_invoice_end_to_end(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'password',
        ]);
        Sanctum::actingAs($user);

        $company = Company::query()->create([
            'name' => 'Acme SA',
            'trade_name' => 'Acme',
            'ruc' => '0999999999001',
            'environment' => 'test',
            'address' => 'Av. Principal 123',
            'requires_accounting' => true,
            'sri_signing_enabled' => true,
            'sri_submission_enabled' => true,
        ]);
        $user->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        CompanyEstablishment::query()->create([
            'company_id' => $company->id,
            'code' => '001',
            'name' => 'Matriz',
            'address' => 'Av. Principal 123',
            'is_active' => true,
        ]);

        $this->activateRealCertificate($company);

        $companyId = $company->id;

        $customerId = $this->postJson("/api/companies/{$companyId}/customers", [
            'name' => 'Cliente Uno',
            'identification_type' => '05',
            'identification_number' => '0912345678',
            'address' => 'Guayaquil',
        ])->assertCreated()->json('id');

        $this->postJson("/api/companies/{$companyId}/products", [
            'code' => 'SKU-1',
            'name' => 'Producto A',
            'unit_price' => 20,
            'tax_rate' => 15,
            'is_active' => true,
        ])->assertCreated();

        $invoiceId = $this->postJson("/api/companies/{$companyId}/invoices", [
            'customer_id' => $customerId,
            'document_code' => '01',
            'items' => [
                ['code' => 'SKU-1', 'name' => 'Producto A', 'quantity' => 2, 'unit_price' => 20, 'discount' => 0, 'tax_rate' => 15],
            ],
        ])->assertCreated()->json('id');

        $this->postJson("/api/companies/{$companyId}/invoices/{$invoiceId}/emit")->assertOk();

        $invoice = Invoice::query()->findOrFail($invoiceId);

        $this->assertSame(InvoiceStatus::Authorized, $invoice->status);
        $this->assertNotNull($invoice->access_key);
        $this->assertSame(49, strlen($invoice->access_key));
    }

    private function activateRealCertificate(Company $company): void
    {
        $dir = sys_get_temp_dir().'/sicofac-real-pipeline-'.uniqid();
        mkdir($dir);
        $keyPath = "$dir/key.pem";
        $certPath = "$dir/cert.pem";
        $p12Path = "$dir/cert.p12";
        $password = 'test-pass';

        exec(sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes -subj "/C=EC/O=SRI PRUEBAS/CN=Acme SA" 2>&1',
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
        ), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'openssl req failed: '.implode("\n", $output));

        exec(sprintf(
            'openssl pkcs12 -export -out %s -inkey %s -in %s -passout pass:%s 2>&1',
            escapeshellarg($p12Path),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($password),
        ), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'openssl pkcs12 export failed: '.implode("\n", $output));

        $uploadedFile = new UploadedFile($p12Path, 'cert.p12', 'application/x-pkcs12', null, true);
        $service = app(CompanyCertificateService::class);
        $certificate = $service->uploadCertificate($company, $uploadedFile, $password);
        $service->activateCertificate($company, $certificate->version);
    }
}
