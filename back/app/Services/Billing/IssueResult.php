<?php

namespace App\Services\Billing;

use App\Models\Invoice;

final readonly class IssueResult
{
    public function __construct(
        public Invoice $invoice,
        public bool $created,
    ) {
    }
}
