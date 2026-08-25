<?php

namespace App\Services\Billing;

use App\DTOs\Billing\InvoiceTotalsData;
use App\Enums\TaxCode;
use App\Models\Invoice;

class InvoiceTotalsCalculator
{
    public function calculate(Invoice $invoice): InvoiceTotalsData
    {
        $invoice->loadMissing('items');

        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $ice = 0.0;
        $total = 0.0;

        $buckets = [];
        foreach (TaxCode::cases() as $code) {
            $buckets[$code->value] = ['subtotal' => 0.0, 'tax' => 0.0];
        }

        foreach ($invoice->items as $item) {
            $itemSubtotal = (float) $item->subtotal;
            $itemTax = (float) $item->tax_amount;
            $itemIce = (float) $item->ice_amount;

            $subtotal += $itemSubtotal;
            $discount += (float) $item->discount;
            $tax += $itemTax;
            $ice += $itemIce;
            $total += (float) $item->total;

            $code = $item->tax_code instanceof TaxCode ? $item->tax_code->value : (string) $item->tax_code;
            if (isset($buckets[$code])) {
                $buckets[$code]['subtotal'] += $itemSubtotal;
                $buckets[$code]['tax'] += $itemTax;
            }
        }

        $tipAmount = 0.0;
        if ($invoice->has_tip) {
            $tipAmount = round($subtotal * 0.10, 2);
            $total += $tipAmount;
        }

        return new InvoiceTotalsData(
            subtotal: round($subtotal, 2),
            discount: round($discount, 2),
            tax: round($tax, 2),
            total: round($total, 2),
            subtotal15: round($buckets[TaxCode::Rate15->value]['subtotal'], 2),
            subtotal5: round($buckets[TaxCode::Rate5->value]['subtotal'], 2),
            subtotalSpecial: round($buckets[TaxCode::Special->value]['subtotal'], 2),
            subtotalZero: round($buckets[TaxCode::RateZero->value]['subtotal'], 2),
            subtotalNotSubject: round($buckets[TaxCode::NotSubject->value]['subtotal'], 2),
            subtotalExempt: round($buckets[TaxCode::Exempt->value]['subtotal'], 2),
            tax15: round($buckets[TaxCode::Rate15->value]['tax'], 2),
            tax5: round($buckets[TaxCode::Rate5->value]['tax'], 2),
            taxSpecial: round($buckets[TaxCode::Special->value]['tax'], 2),
            iceTotal: round($ice, 2),
            tipAmount: $tipAmount,
        );
    }
}
