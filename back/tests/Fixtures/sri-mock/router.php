<?php

// Entry point for `php -S` in RealSriClientTest: serves the WSDL on a
// "?wsdl" GET (mirroring the real SRI endpoints' "...?wsdl" URLs) and
// dispatches SOAP calls otherwise.
require __DIR__.'/handler.php';

$location = 'http://'.$_SERVER['HTTP_HOST'].'/router.php';

if (array_key_exists('wsdl', $_GET)) {
    header('Content-Type: text/xml');
    echo (require __DIR__.'/mock.wsdl.php')($location);

    return;
}

$wsdlFile = tempnam(sys_get_temp_dir(), 'sri-mock-').'.wsdl';
file_put_contents($wsdlFile, (require __DIR__.'/mock.wsdl.php')($location));

$server = new SoapServer($wsdlFile, ['cache_wsdl' => WSDL_CACHE_NONE]);
$server->setClass(MockSriHandler::class);
$server->handle();

unlink($wsdlFile);
