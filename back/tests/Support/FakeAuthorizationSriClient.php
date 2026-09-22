<?php

namespace Tests\Support;

use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Integrations\Sri\DTOs\SriAuthorizationResponseDTO;
use App\Integrations\Sri\DTOs\SriReceptionResponseDTO;

/**
 * Test double for SriClientInterface that returns a single pre-built
 * authorization response (or throws a given exception) from
 * checkAuthorization(), so CheckAuthorizationJob's branching logic can be
 * exercised directly without a real/mock SOAP round trip.
 */
class FakeAuthorizationSriClient implements SriClientInterface
{
    public function __construct(
        private readonly ?SriAuthorizationResponseDTO $response = null,
        private readonly ?\Throwable $exception = null,
    ) {
    }

    public function sendToReception(string $signedXml, string $environment): SriReceptionResponseDTO
    {
        throw new \RuntimeException('FakeAuthorizationSriClient does not support sendToReception().');
    }

    public function checkAuthorization(string $accessKey, string $environment): SriAuthorizationResponseDTO
    {
        if ($this->exception) {
            throw $this->exception;
        }

        return $this->response;
    }
}
