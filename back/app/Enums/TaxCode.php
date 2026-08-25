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
}
