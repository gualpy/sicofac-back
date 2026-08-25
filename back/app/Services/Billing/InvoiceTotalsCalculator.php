<?php

namespace App\Services\Billing;

use App\DTOs\Billing\InvoiceTotalsData;
use App\Models\Invoice;

class InvoiceTotalsCalculator
{
    public function calculate(Invoice $invoice): InvoiceTotalsData
    {
        $invoice->loadMissing('items');

        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;
        $total = 0.0;

        foreach ($invoice->items as $item) {
            $subtotal += (float) $item->subtotal;
            $discount += (float) $item->discount;
            $tax += (float) $item->tax_amount;
            $total += (float) $item->total;
        }

        return new InvoiceTotalsData(
            subtotal: round($subtotal, 2),
            discount: round($discount, 2),
            tax: round($tax, 2),
            total: round($total, 2),
        );
    }
}

