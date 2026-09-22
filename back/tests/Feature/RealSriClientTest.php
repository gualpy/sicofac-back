<?php

namespace Tests\Feature;

use App\Integrations\Sri\Enums\SriAuthorizationStatus;
use App\Integrations\Sri\Real\RealSriClient;
use Tests\TestCase;

class RealSriClientTest extends TestCase
{
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

        $this->waitForServer();

        config([
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

    private function waitForServer(): void
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);
            if ($connection) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }

        $this->fail('Mock SRI server did not start in time.');
    }

    public function test_reception_reports_success_and_extracts_the_embedded_access_key(): void
    {
        $xml = '<factura id="comprobante"><infoTributaria><claveAcceso>1234567890123456789012345678901234567890123456789</claveAcceso></infoTributaria></factura>';

        $response = (new RealSriClient())->sendToReception($xml, 'test');

        $this->assertTrue($response->success);
        $this->assertSame('1234567890123456789012345678901234567890123456789', $response->accessKey);
        $this->assertSame('RECIBIDA', $response->payload['estado']);
    }

    public function test_reception_reports_rejection_with_messages(): void
    {
        $xml = '<factura id="comprobante">FORZAR_RECHAZO</factura>';

        $response = (new RealSriClient())->sendToReception($xml, 'test');

        $this->assertFalse($response->success);
        $this->assertNull($response->accessKey);
        $this->assertNotEmpty($response->messages);
        $this->assertStringContainsString('DOCUMENTO INVALIDO', $response->messages[0]);
    }

    public function test_authorization_reports_authorized(): void
    {
        $response = (new RealSriClient())->checkAuthorization('AK-OK-123', 'test');

        $this->assertSame(SriAuthorizationStatus::Authorized, $response->status);
        $this->assertTrue($response->authorized);
        $this->assertSame('AK-OK-123', $response->authorizationNumber);
    }

    public function test_authorization_reports_rejection_with_messages(): void
    {
        $response = (new RealSriClient())->checkAuthorization('AK-RECHAZAR-123', 'test');

        $this->assertSame(SriAuthorizationStatus::Rejected, $response->status);
        $this->assertFalse($response->authorized);
        $this->assertNull($response->authorizationNumber);
        $this->assertNotEmpty($response->messages);
        $this->assertStringContainsString('RUC no existe', $response->messages[0]);
    }

    public function test_authorization_reports_pending_when_sri_is_still_processing(): void
    {
        $response = (new RealSriClient())->checkAuthorization('AK-PPR-123', 'test');

        $this->assertSame(SriAuthorizationStatus::Pending, $response->status);
        $this->assertFalse($response->authorized);
        $this->assertNull($response->authorizationNumber);
    }
}
