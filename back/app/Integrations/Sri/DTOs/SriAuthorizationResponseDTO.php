<?php

namespace App\Integrations\Sri\DTOs;

use App\Integrations\Sri\Enums\SriAuthorizationStatus;

final readonly class SriAuthorizationResponseDTO
{
    /**
     * @param  array<int, string>  $messages  Human-readable "code - text - extra" summary, kept for
     *                                        backward compatibility with existing consumers/tests.
     * @param  array<int, array{code: ?string, message: string, additional_info: ?string, type: ?string}>  $messageDetails
     *                                        Structured SRI mensaje nodes, empty for drivers without real ones.
     */
    public function __construct(
        public SriAuthorizationStatus $status,
        public bool $authorized,
        public ?string $authorizationNumber,
        public array $messages = [],
        public array $messageDetails = [],
        public array $payload = [],
    ) {
    }
}
