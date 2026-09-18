<?php

namespace App\Integrations\Sri\Real;

use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Integrations\Sri\DTOs\SriAuthorizationResponseDTO;
use App\Integrations\Sri\DTOs\SriReceptionResponseDTO;
use DOMDocument;
use RuntimeException;
use SoapClient;
use SoapFault;

/**
 * Talks to the real SRI SOAP web services (Ficha Tecnica de Comprobantes
 * Electronicos, numeral 7): RecepcionComprobantesOffline (validarComprobante)
 * and AutorizacionComprobantesOffline (autorizacionComprobante). Endpoints
 * are picked per-call from config('sri.wsdl'), keyed by the company's own
 * "test"/"production" environment (numeral 7.2 lists two separate WSDL
 * sets, one per ambiente -- there is no single endpoint that serves both).
 */
class RealSriClient implements SriClientInterface
{
    public function sendToReception(string $signedXml, string $environment): SriReceptionResponseDTO
    {
        $client = $this->client('reception', $environment);

        try {
            // The real WSDL is document/literal *wrapped*: the single input
            // part is the <validarComprobante> element itself, whose only
            // child is "xml" (byte[]/base64Binary, auto-encoded by ext-soap
            // from a plain string). That means __soapCall's one positional
            // argument must be the associative array matching that child
            // element -- a bare string here silently sends an empty/null
            // parameter (confirmed against the real SRI ambiente de pruebas:
            // it rejects with "consumido con argumentos nulos").
            $result = $client->__soapCall('validarComprobante', [['xml' => $signedXml]]);
        } catch (SoapFault $fault) {
            throw new RuntimeException('SRI reception WS call failed: '.$fault->getMessage(), previous: $fault);
        }

        // Same document/literal wrapping on the way out: the response
        // element wraps a single "RespuestaRecepcionComprobante" child, so
        // __soapCall returns an object with that property, not the content
        // directly (confirmed against the real WSDL and a live call).
        $respuesta = $result->RespuestaRecepcionComprobante ?? null;
        if (! is_object($respuesta) || ! isset($respuesta->estado)) {
            throw new RuntimeException('Unexpected SRI reception response shape: '.json_encode($result));
        }

        $estado = (string) ($respuesta->estado ?? '');
        $success = $estado === 'RECIBIDA';

        $comprobantes = $this->normalizeList($respuesta->comprobantes->comprobante ?? null);
        $messages = [];
        foreach ($comprobantes as $comprobante) {
            $messages = [...$messages, ...$this->extractMessages($comprobante->mensajes ?? null)];
        }

        return new SriReceptionResponseDTO(
            success: $success,
            // The reception WS does not echo the access key back (see the
            // "RECIBIDA" example, <comprobantes/> is empty on success); we
            // already generated and embedded it ourselves when building the
            // XML (see AccessKeyGenerator), so just read it back out.
            accessKey: $success ? $this->extractAccessKey($signedXml) : null,
            messages: $messages,
            payload: [
                'mode' => 'real',
                'environment' => $environment,
                'estado' => $estado,
                'received_at' => now()->toIso8601String(),
            ],
        );
    }

    public function checkAuthorization(string $accessKey, string $environment): SriAuthorizationResponseDTO
    {
        $client = $this->client('authorization', $environment);

        try {
            // Same document/literal wrapped shape as sendToReception() above.
            $result = $client->__soapCall('autorizacionComprobante', [['claveAccesoComprobante' => $accessKey]]);
        } catch (SoapFault $fault) {
            throw new RuntimeException('SRI authorization WS call failed: '.$fault->getMessage(), previous: $fault);
        }

        $respuesta = $result->RespuestaAutorizacionComprobante ?? null;
        if (! is_object($respuesta) || ! isset($respuesta->autorizaciones)) {
            throw new RuntimeException('Unexpected SRI authorization response shape: '.json_encode($result));
        }

        $autorizaciones = $this->normalizeList($respuesta->autorizaciones->autorizacion ?? null);
        $primary = $autorizaciones[0] ?? null;

        $estado = $primary ? (string) ($primary->estado ?? '') : '';
        $authorized = $estado === 'AUTORIZADO';

        return new SriAuthorizationResponseDTO(
            authorized: $authorized,
            authorizationNumber: $primary->numeroAutorizacion ?? null,
            messages: $primary ? $this->extractMessages($primary->mensajes ?? null) : [],
            payload: [
                'mode' => 'real',
                'environment' => $environment,
                'estado' => $estado,
                'fecha_autorizacion' => $primary->fechaAutorizacion ?? null,
                'access_key' => $accessKey,
                'checked_at' => now()->toIso8601String(),
            ],
        );
    }

    private function client(string $service, string $environment): SoapClient
    {
        $env = $environment === 'production' ? 'production' : 'test';
        $wsdl = config("sri.wsdl.{$service}.{$env}");

        if (! $wsdl) {
            throw new RuntimeException("No SRI WSDL configured for service [{$service}] environment [{$env}].");
        }

        return new SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'connection_timeout' => 30,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ]);
    }

    /**
     * ext-soap decodes a repeatable element as a single stdClass when the
     * response carries exactly one, or as an array when it carries several
     * -- normalize both shapes to a plain list.
     */
    private function normalizeList(mixed $node): array
    {
        if ($node === null) {
            return [];
        }

        return is_array($node) ? array_values($node) : [$node];
    }

    private function extractMessages(mixed $mensajesContainer): array
    {
        if ($mensajesContainer === null) {
            return [];
        }

        $messages = [];
        foreach ($this->normalizeList($mensajesContainer->mensaje ?? null) as $mensaje) {
            $parts = array_filter([
                $mensaje->identificador ?? null,
                $mensaje->mensaje ?? null,
                $mensaje->informacionAdicional ?? null,
            ]);
            $messages[] = implode(' - ', $parts);
        }

        return $messages;
    }

    private function extractAccessKey(string $signedXml): ?string
    {
        $document = new DOMDocument();
        if (! @$document->loadXML($signedXml)) {
            return null;
        }

        $node = $document->getElementsByTagName('claveAcceso')->item(0);

        return $node?->textContent ?: null;
    }
}
