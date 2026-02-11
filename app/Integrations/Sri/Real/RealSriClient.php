<?php

namespace App\Integrations\Sri\Real;

use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Integrations\Sri\DTOs\SriAuthorizationResponseDTO;
use App\Integrations\Sri\DTOs\SriReceptionResponseDTO;
use RuntimeException;

class RealSriClient implements SriClientInterface
{
    public function sendToReception(string $signedXml): SriReceptionResponseDTO
    {
        throw new RuntimeException('Real SRI client is not implemented yet.');
    }

    public function checkAuthorization(string $accessKey): SriAuthorizationResponseDTO
    {
        throw new RuntimeException('Real SRI client is not implemented yet.');
    }
}
