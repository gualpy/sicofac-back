<?php

namespace Tests\Unit;

use App\Services\Certificates\Pkcs12Reader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Covers the real-world case this class exists for: older Ecuadorian SRI
 * certificates exported with RC2-40-CBC, which OpenSSL 3.0's default
 * provider refuses to decrypt (openssl_pkcs12_read() fails outright), and
 * which only the `openssl` CLI's -legacy flag can still read.
 */
class Pkcs12ReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/sicofac-pkcs12-test-'.uniqid();
        mkdir($this->dir);
    }

    private function makeP12(string $password, bool $legacy): string
    {
        $keyPath = "{$this->dir}/key.pem";
        $certPath = "{$this->dir}/cert.pem";
        $p12Path = "{$this->dir}/cert.p12";

        exec(sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes -subj "/C=EC/O=SRI PRUEBAS/CN=Acme SA" 2>&1',
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
        ), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'openssl req failed: '.implode("\n", $output));

        $legacyFlag = $legacy ? '-legacy' : '';
        exec(sprintf(
            'openssl pkcs12 -export -out %s -inkey %s -in %s -passout pass:%s %s 2>&1',
            escapeshellarg($p12Path),
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($password),
            $legacyFlag,
        ), $output, $exitCode);
        $this->assertSame(0, $exitCode, 'openssl pkcs12 export failed: '.implode("\n", $output));

        return $p12Path;
    }

    public function test_it_reads_a_modern_p12_via_native_openssl(): void
    {
        $path = $this->makeP12('test-pass', legacy: false);

        $result = Pkcs12Reader::read($path, 'test-pass');

        $this->assertStringContainsString('BEGIN CERTIFICATE', $result['cert']);
        $this->assertStringContainsString('PRIVATE KEY', $result['pkey']);
    }

    public function test_it_falls_back_to_the_legacy_cli_for_rc2_encrypted_p12(): void
    {
        $path = $this->makeP12('test-pass', legacy: true);

        // Sanity check: this fixture actually reproduces the real-world
        // failure -- native openssl_pkcs12_read() must fail on it, or this
        // test would not be exercising the fallback at all.
        $certs = [];
        $this->assertFalse(@openssl_pkcs12_read(file_get_contents($path), $certs, 'test-pass'));

        $result = Pkcs12Reader::read($path, 'test-pass');

        $this->assertStringContainsString('BEGIN CERTIFICATE', $result['cert']);
        $this->assertStringContainsString('PRIVATE KEY', $result['pkey']);
    }

    public function test_it_throws_on_wrong_password_for_a_legacy_p12(): void
    {
        $path = $this->makeP12('test-pass', legacy: true);

        $this->expectException(RuntimeException::class);

        Pkcs12Reader::read($path, 'wrong-password');
    }
}
