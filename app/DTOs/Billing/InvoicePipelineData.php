<?php

namespace App\DTOs\Billing;

final readonly class InvoicePipelineData
{
    public function __construct(
        public int $invoiceId,
        public bool $signingEnabled,
        public bool $submissionEnabled,
    ) {
    }
}

