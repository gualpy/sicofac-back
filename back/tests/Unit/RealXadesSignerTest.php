<?php

namespace Tests\Unit;

use App\Integrations\Sri\Real\RealXadesSigner;
use App\Models\Company;
use App\Models\CompanyCertificate;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealXadesSignerTest extends TestCase
{
    use RefreshDatabase;

    private const SAMPLE_XML = '<factura id="comprobante" version="2.1.0"><infoTributaria><ruc>0999999999001</ruc></infoTributaria><infoFactura><importeTotal>46.00</importeTotal></infoFactura></factura>';

    private function makeTestCertificate(string $password = 'test-pass'): CompanyCertificate
    {
        $company = Company::query()->create([
            'name' => 'Acme SA',
            'ruc' => '0999999999001',
            'environment' => 'test',
        ]);

        $dir = sys_get_temp_dir().'/sicofac-xades-test-'.uniqid();
        mkdir($dir);
        $keyPath = "$dir/key.pem";
        $certPath = "$dir/cert.pem";
        $p12Path = "$dir/cert.p12";

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

        $path = 'certificates/'.$company->id.'/cert_v1.p12';
        Storage::disk('local')->put($path, file_get_contents($p12Path));

        return CompanyCertificate::query()->create([
            'company_id' => $company->id,
            'version' => 1,
            'scope_type' => 'company',
            'path' => $path,
            'status' => 'active',
            'is_active' => true,
            'password_encrypted' => Crypt::encryptString($password),
            'uploaded_at' => now(),
        ]);
    }

    private function c14nDigest(string $fragment): string
    {
        $document = new DOMDocument();
        $document->loadXML($fragment);

        return base64_encode(sha1($document->documentElement->C14N(), true));
    }

    public function test_it_produces_a_cryptographically_valid_xades_bes_signature(): void
    {
        $certificate = $this->makeTestCertificate();

        $signed = app(RealXadesSigner::class)->sign(self::SAMPLE_XML, $certificate);

        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($signed), 'Signed XML must be well-formed.');

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $xpath->registerNamespace('etsi', 'http://uri.etsi.org/01903/v1.3.2#');

        $signatureNodes = $xpath->query('//ds:Signature');
        $this->assertSame(1, $signatureNodes->length);

        // 1. The <factura id="comprobante"> content (minus the signature we just
        // stripped it from, i.e. the original input) must match the digested
        // enveloped reference.
        $expectedComprobanteDigest = $this->c14nDigest(self::SAMPLE_XML);
        $comprobanteDigest = (string) $xpath->query('//ds:Reference[@URI="#comprobante"]/ds:DigestValue')[0]->nodeValue;
        $this->assertSame($expectedComprobanteDigest, $comprobanteDigest);

        // 2. SignedProperties digest must match a fresh C14N+SHA1 of that node.
        $signedPropertiesNode = $xpath->query('//etsi:SignedProperties')[0];
        $expectedSignedPropsDigest = base64_encode(sha1($signedPropertiesNode->C14N(), true));
        $signedPropsDigest = (string) $xpath->query('//ds:Reference[@Type="http://uri.etsi.org/01903#SignedProperties"]/ds:DigestValue')[0]->nodeValue;
        $this->assertSame($expectedSignedPropsDigest, $signedPropsDigest);

        // 3. KeyInfo digest.
        $keyInfoNode = $xpath->query('//ds:KeyInfo')[0];
        $keyInfoId = $keyInfoNode->getAttribute('id') ?: $keyInfoNode->getAttribute('Id');
        $expectedKeyInfoDigest = base64_encode(sha1($keyInfoNode->C14N(), true));
        $keyInfoRefDigest = (string) $xpath->query('//ds:Reference[@URI="#'.$keyInfoId.'"]/ds:DigestValue')[0]->nodeValue;
        $this->assertSame($expectedKeyInfoDigest, $keyInfoRefDigest);

        // 4. The SignatureValue must actually verify against the embedded
        // certificate's public key, over the canonicalized SignedInfo.
        $signedInfoNode = $xpath->query('//ds:SignedInfo')[0];
        $signatureValue = base64_decode((string) $xpath->query('//ds:SignatureValue')[0]->nodeValue);
        $certB64 = (string) $xpath->query('//ds:X509Certificate')[0]->nodeValue;
        $certPem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certB64, 64)."-----END CERTIFICATE-----\n";

        $verified = openssl_verify($signedInfoNode->C14N(), $signatureValue, $certPem, OPENSSL_ALGO_SHA1);
        $this->assertSame(1, $verified, 'SignatureValue must verify against the embedded X509 certificate.');
    }
}
