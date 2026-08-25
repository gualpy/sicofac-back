<?php

namespace App\Services\Billing;

use App\DTOs\Billing\InvoiceItemData;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Facades\DB;

class InvoiceDraftService
{
    public function __construct(
        private readonly DocumentSequenceService $sequenceService,
        private readonly InvoiceTotalsCalculator $totalsCalculator,
    ) {
    }

    /**
     * @param  array<int, InvoiceItemData>  $items
     */
    public function create(
        int $companyId,
        ?int $customerId,
        array $items,
        string $documentCode = '01',
        string $establishmentCode = '001',
        string $emissionPoint = '001',
    ): Invoice {
        return DB::transaction(function () use ($companyId, $customerId, $items, $documentCode, $establishmentCode, $emissionPoint) {
            $invoice = Invoice::query()->create([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'document_code' => $documentCode,
                'establishment_code' => $establishmentCode,
                'emission_point' => $emissionPoint,
                'sequential' => $this->sequenceService->next($companyId, $documentCode, $establishmentCode, $emissionPoint),
                'issue_date' => now()->toDateString(),
                'status' => InvoiceStatus::Draft,
                'currency' => 'USD',
            ]);

            foreach ($items as $item) {
                if (! $item instanceof InvoiceItemData) {
                    continue;
                }

                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $item->productId,
                    'code' => $item->code,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unitPrice,
                    'discount' => $item->discount,
                    'tax_rate' => $item->taxRate,
                    'tax_amount' => $item->taxAmount(),
                    'subtotal' => $item->subtotal(),
                    'total' => $item->total(),
                ]);
            }

            $totals = $this->totalsCalculator->calculate($invoice->fresh('items'));
            $invoice->update([
                'subtotal' => $totals->subtotal,
                'discount' => $totals->discount,
                'tax' => $totals->tax,
                'total' => $totals->total,
            ]);

            return $invoice->fresh(['items', 'company', 'customer']);
        });
    }
}
