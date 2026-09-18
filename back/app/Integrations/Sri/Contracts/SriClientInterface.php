<?php

namespace App\Integrations\Sri\Contracts;

use App\Integrations\Sri\DTOs\SriAuthorizationResponseDTO;
use App\Integrations\Sri\DTOs\SriReceptionResponseDTO;

interface SriClientInterface
{
    /**
     * @param  string  $environment  Company::environment ('test'|'production'),
     *                                selects which SRI WSDL endpoint to hit.
     */
    public function sendToReception(string $signedXml, string $environment): SriReceptionResponseDTO;

    public function checkAuthorization(string $accessKey, string $environment): SriAuthorizationResponseDTO;
}
