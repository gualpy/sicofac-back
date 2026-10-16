<?php

namespace App\Exceptions\Billing;

use DomainException;

/**
 * Client-facing failure of the idempotent issue endpoint. Carries the stable
 * machine-readable code and HTTP status from the FitOS <-> SICOFAC contract.
 */
class IssueException extends DomainException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
