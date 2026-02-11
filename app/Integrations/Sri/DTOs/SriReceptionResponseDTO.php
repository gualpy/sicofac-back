<?php

namespace App\Integrations\Sri\DTOs;

final readonly class SriReceptionResponseDTO
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        public bool $success,
        public ?string $accessKey,
        public array $messages = [],
        public array $payload = [],
    ) {
    }
}
