<?php

namespace App\DTOs\Billing;

final readonly class InvoiceTotalsData
{
    public function __construct(
        public float $subtotal,
        public float $discount,
        public float $tax,
        public float $total,
    ) {
    }
}

