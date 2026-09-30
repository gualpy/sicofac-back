<?php

namespace App\Integrations\Sri\DTOs;

final readonly class SriReceptionResponseDTO
{
    /**
     * @param  array<int, string>  $messages  Human-readable "code - text - extra" summary, kept for
     *                                        backward compatibility with existing consumers/tests.
     * @param  array<int, array{code: ?string, message: string, additional_info: ?string, type: ?string}>  $messageDetails
     *                                        Structured SRI mensaje nodes (identificador/mensaje/informacionAdicional/tipo),
     *                                        empty for drivers (e.g. Dummy) that don't have real SRI message objects.
     */
    public function __construct(
        public bool $success,
        public ?string $accessKey,
        public array $messages = [],
        public array $messageDetails = [],
        public array $payload = [],
    ) {
    }
}
