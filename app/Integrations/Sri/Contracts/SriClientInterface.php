<?php

namespace App\Integrations\Sri\Contracts;

use App\Integrations\Sri\DTOs\SriAuthorizationResponseDTO;
use App\Integrations\Sri\DTOs\SriReceptionResponseDTO;

interface SriClientInterface
{
    public function sendToReception(string $signedXml): SriReceptionResponseDTO;

    public function checkAuthorization(string $accessKey): SriAuthorizationResponseDTO;
}
