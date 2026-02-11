<?php

namespace App\Integrations\Sri\DTOs;

final readonly class SriAuthorizationResponseDTO
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        public bool $authorized,
        public ?string $authorizationNumber,
        public array $messages = [],
        public array $payload = [],
    ) {
    }
}
