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

    /**
     * @param  array<string, mixed>  $item  validated invoice item payload
     */
    public static function fromArray(array $item): self
    {
        $taxRate = (float) ($item['tax_rate'] ?? 0);
        $taxCode = isset($item['tax_code'])
            ? TaxCode::from((string) $item['tax_code'])
            : TaxCode::fromRate($taxRate);

        return new self(
            code: (string) $item['code'],
            name: (string) $item['name'],
            quantity: (float) $item['quantity'],
            unitPrice: (float) $item['unit_price'],
            productId: isset($item['product_id']) ? (int) $item['product_id'] : null,
            discount: (float) ($item['discount'] ?? 0),
            taxRate: $taxRate,
            taxCode: $taxCode,
            iceRate: (float) ($item['ice_rate'] ?? 0),
            iceCode: $item['ice_code'] ?? null,
        );
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
