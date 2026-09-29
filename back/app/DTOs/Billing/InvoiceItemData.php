<?php

namespace App\DTOs\Billing;

use App\Enums\TaxCode;

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
        public TaxCode $taxCode = TaxCode::Rate15,
        public float $iceRate = 0.0,
        public ?string $iceCode = null,
    ) {
    }

    public function subtotal(): float
    {
        return max(0, $this->quantity * $this->unitPrice - $this->discount);
    }

    public function iceAmount(): float
    {
        return round($this->subtotal() * ($this->iceRate / 100), 2);
    }

    /**
     * IVA is calculated on the subtotal plus ICE, since ICE widens the
     * taxable base per SRI rules.
     */
    public function taxAmount(): float
    {
        return round(($this->subtotal() + $this->iceAmount()) * ($this->taxRate / 100), 2);
    }

    public function total(): float
    {
        return round($this->subtotal() + $this->iceAmount() + $this->taxAmount(), 2);
    }
}
