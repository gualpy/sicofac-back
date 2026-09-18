<?php

/**
 * Stand-in for the SRI's real RecepcionComprobantesOffline /
 * AutorizacionComprobantesOffline services (see Ficha Tecnica numeral 7.1.3
 * / 7.2). Response shapes below are copied from the ficha's own worked
 * examples. Scenario is picked from the request content so tests can hit
 * both the happy path and a rejection without a second server.
 */
class MockSriHandler
{
    /**
     * Document/literal *wrapped* style: SoapServer does NOT decompose the
     * single input element into separate method parameters -- it hands the
     * whole wrapper object as one argument (confirmed empirically; several
     * docs/tutorials claim otherwise). Same on the way out: the return
     * value must itself be wrapped under the response element's child name,
     * or SoapServer serializes an empty response body.
     */
    public function validarComprobante($params)
    {
        $xml = $params->xml ?? '';

        if (str_contains((string) $xml, 'FORZAR_RECHAZO')) {
            return (object) ['RespuestaRecepcionComprobante' => (object) [
                'estado' => 'DEVUELTA',
                'comprobantes' => (object) [
                    'comprobante' => (object) [
                        'claveAcceso' => '1702201205176001321000110010030001000011234567816',
                        'mensajes' => (object) [
                            'mensaje' => (object) [
                                'identificador' => '35',
                                'mensaje' => 'DOCUMENTO INVALIDO',
                                'informacionAdicional' => 'Estructura invalida',
                                'tipo' => 'ERROR',
                            ],
                        ],
                    ],
                ],
            ]];
        }

        return (object) ['RespuestaRecepcionComprobante' => (object) ['estado' => 'RECIBIDA']];
    }

    public function autorizacionComprobante($params)
    {
        $claveAccesoComprobante = $params->claveAccesoComprobante ?? '';

        if (str_contains((string) $claveAccesoComprobante, 'RECHAZAR')) {
            return (object) ['RespuestaAutorizacionComprobante' => (object) [
                'claveAccesoConsultada' => $claveAccesoComprobante,
                'numeroComprobantes' => '1',
                'autorizaciones' => (object) [
                    'autorizacion' => (object) [
                        'estado' => 'RECHAZADO',
                        'fechaAutorizacion' => '2026-08-29T10:00:00.000-05:00',
                        'ambiente' => 'PRUEBAS',
                        'mensajes' => (object) [
                            'mensaje' => (object) [
                                'identificador' => '46',
                                'mensaje' => 'RUC no existe',
                                'tipo' => 'ERROR',
                            ],
                        ],
                    ],
                ],
            ]];
        }

        return (object) ['RespuestaAutorizacionComprobante' => (object) [
            'claveAccesoConsultada' => $claveAccesoComprobante,
            'numeroComprobantes' => '1',
            'autorizaciones' => (object) [
                'autorizacion' => (object) [
                    'estado' => 'AUTORIZADO',
                    'numeroAutorizacion' => $claveAccesoComprobante,
                    'fechaAutorizacion' => '2026-08-29T10:00:00.000-05:00',
                    'ambiente' => 'PRUEBAS',
                ],
            ],
        ]];
    }
}
