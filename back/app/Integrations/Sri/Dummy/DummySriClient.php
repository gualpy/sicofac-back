<?php

namespace App\Integrations\Sri\Dummy;

use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Integrations\Sri\DTOs\SriAuthorizationResponseDTO;
use App\Integrations\Sri\DTOs\SriReceptionResponseDTO;
use Illuminate\Support\Str;

class DummySriClient implements SriClientInterface
{
    public function sendToReception(string $signedXml): SriReceptionResponseDTO
    {
        return new SriReceptionResponseDTO(
            success: true,
            accessKey: Str::upper(Str::random(49)),
            messages: ['RECEIVED'],
            payload: [
                'mode' => 'dummy',
                'received_at' => now()->toIso8601String(),
            ],
        );
    }

    public function checkAuthorization(string $accessKey): SriAuthorizationResponseDTO
    {
        return new SriAuthorizationResponseDTO(
            authorized: true,
            authorizationNumber: Str::upper(Str::random(37)),
            messages: ['AUTHORIZED'],
            payload: [
                'mode' => 'dummy',
                'authorized_at' => now()->toIso8601String(),
                'access_key' => $accessKey,
            ],
        );
    }
}
