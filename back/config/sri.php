<?php

return [
    'driver' => env('SRI_DRIVER', 'dummy'),

    // Official SRI SOAP endpoints (Ficha Tecnica de Comprobantes
    // Electronicos, numeral 7.2). The SRI explicitly warns these can change
    // without notice, hence configurable via env rather than hardcoded.
    'wsdl' => [
        'reception' => [
            'test' => env('SRI_WSDL_RECEPTION_TEST', 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl'),
            'production' => env('SRI_WSDL_RECEPTION_PRODUCTION', 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline?wsdl'),
        ],
        'authorization' => [
            'test' => env('SRI_WSDL_AUTHORIZATION_TEST', 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'),
            'production' => env('SRI_WSDL_AUTHORIZATION_PRODUCTION', 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl'),
        ],
    ],
];
