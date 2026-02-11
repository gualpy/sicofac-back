<?php

namespace App\DTOs\Billing;

final readonly class InvoiceItemData
{
    public function __construct(
        public string $code,
        public string $name,
        public float $quantity,
        public float $unitPrice,
        public ?int $productId = null,
        public float $discount = 0.0,
        public float $taxRate = 0.0,
    ) {
    }

    public function subtotal(): float
    {
        return max(0, $this->quantity * $this->unitPrice - $this->discount);
    }

    public function taxAmount(): float
    {
        return round($this->subtotal() * ($this->taxRate / 100), 2);
    }

    public function total(): float
    {
        return round($this->subtotal() + $this->taxAmount(), 2);
    }
}
