<?php

namespace App\DTOs\Billing;

final readonly class SriSubmissionResultData
{
    public function __construct(
        public bool $authorized,
        public ?string $authorizationNumber,
        public array $payload = [],
    ) {
    }
}

