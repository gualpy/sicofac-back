<?php

namespace App\Integrations\Sri\DTOs;

use App\Integrations\Sri\Enums\SriAuthorizationStatus;

final readonly class SriAuthorizationResponseDTO
{
    /**
     * @param  array<int, string>  $messages
     */
    public function __construct(
        public SriAuthorizationStatus $status,
        public bool $authorized,
        public ?string $authorizationNumber,
        public array $messages = [],
        public array $payload = [],
    ) {
    }
}
