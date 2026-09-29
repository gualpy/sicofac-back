<?php

namespace App\Enums;

/**
 * Simplified IVA classification used to bucket invoice item subtotals in
 * the totals summary, matching the SRI "Facturador" totals breakdown
 * (Subtotal 15% / 5% / tarifa especial / 0% / no objeto de IVA / exento).
 */
enum TaxCode: string
{
    case Rate15 = '15';
    case Rate5 = '5';
    case Special = 'especial';
    case RateZero = '0';
    case NotSubject = 'no_objeto';
    case Exempt = 'exento';

    public static function fromRate(float $rate): self
    {
        return match (true) {
            $rate === 5.0 => self::Rate5,
            $rate === 0.0 => self::RateZero,
            default => self::Rate15,
        };
    }

    /**
     * The IVA percentage implied by this code, or null when the code doesn't
     * fix a single rate (Special/"tarifa especial" varies case by case, so
     * tax_rate must be set explicitly for it).
     */
    public function fixedRate(): ?float
    {
        return match ($this) {
            self::Rate15 => 15.0,
            self::Rate5 => 5.0,
            self::RateZero, self::NotSubject, self::Exempt => 0.0,
            self::Special => null,
        };
    }
}
