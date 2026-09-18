<?php

namespace App\Services\Certificates;

use RuntimeException;

/**
 * Reads a PKCS12 (.p12/.pfx) file into its certificate + private key PEM
 * pair. Prefers PHP's native openssl_pkcs12_read(), but many older
 * Ecuadorian SRI certificates (Banco Central del Ecuador, Security Data,
 * etc.) were issued encrypted with RC2-40-CBC, an algorithm OpenSSL 3.0
 * disabled in its default provider. openssl_pkcs12_read() has no way to opt
 * into OpenSSL's "legacy" provider for a single call, but the `openssl` CLI
 * does via -legacy, so that's the fallback when the native read fails.
 */
class Pkcs12Reader
{
    /**
     * @return array{cert: string, pkey: string}
     */
    public static function read(string $filePath, string $password): array
    {
        $certs = [];
        $content = file_get_contents($filePath);
        if ($content !== false && @openssl_pkcs12_read($content, $certs, $password)) {
            return ['cert' => $certs['cert'], 'pkey' => $certs['pkey']];
        }

        return self::readViaLegacyCli($filePath, $password);
    }

    /**
     * @return array{cert: string, pkey: string}
     */
    private static function readViaLegacyCli(string $filePath, string $password): array
    {
        $process = proc_open(
            ['openssl', 'pkcs12', '-legacy', '-in', $filePath, '-nodes', '-passin', 'stdin'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Invalid PKCS12 certificate or password.');
        }

        fwrite($pipes[0], $password);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || $output === false) {
            throw new RuntimeException('Invalid PKCS12 certificate or password.');
        }

        preg_match('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $output, $certMatch);
        preg_match('/-----BEGIN (?:RSA |ENCRYPTED )?PRIVATE KEY-----.*?-----END (?:RSA |ENCRYPTED )?PRIVATE KEY-----/s', $output, $keyMatch);

        if (! $certMatch || ! $keyMatch) {
            throw new RuntimeException('Invalid PKCS12 certificate or password.');
        }

        return ['cert' => $certMatch[0], 'pkey' => $keyMatch[0]];
    }
}
