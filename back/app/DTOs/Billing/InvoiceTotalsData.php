<?php

namespace App\DTOs\Billing;

final readonly class InvoiceTotalsData
{
    public function __construct(
        public float $subtotal,
        public float $discount,
        public float $tax,
        public float $total,
        public float $subtotal15 = 0.0,
        public float $subtotal5 = 0.0,
        public float $subtotalSpecial = 0.0,
        public float $subtotalZero = 0.0,
        public float $subtotalNotSubject = 0.0,
        public float $subtotalExempt = 0.0,
        public float $tax15 = 0.0,
        public float $tax5 = 0.0,
        public float $taxSpecial = 0.0,
        public float $iceTotal = 0.0,
        public float $tipAmount = 0.0,
    ) {
    }
}
