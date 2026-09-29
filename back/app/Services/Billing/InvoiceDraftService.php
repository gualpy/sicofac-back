<?php

namespace App\Services\Billing;

use App\DTOs\Billing\InvoiceItemData;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceAdditionalField;
use App\Models\InvoiceItem;
use App\Models\InvoicePaymentMethod;
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
     * @param  array<int, array{method: string, value: float, term_value: ?int, term_unit: ?string}>  $paymentMethods
     * @param  array<int, array{name: string, description: string}>  $additionalFields
     */
    public function create(
        int $companyId,
        ?int $customerId,
        array $items,
        string $documentCode = '01',
        string $establishmentCode = '001',
        string $emissionPoint = '001',
        ?string $guideNumber = null,
        bool $isNegotiable = false,
        bool $hasTip = false,
        array $paymentMethods = [],
        array $additionalFields = [],
    ): Invoice {
        return DB::transaction(function () use (
            $companyId, $customerId, $items, $documentCode, $establishmentCode, $emissionPoint,
            $guideNumber, $isNegotiable, $hasTip, $paymentMethods, $additionalFields,
        ) {
            $invoice = Invoice::query()->create([
                'company_id' => $companyId,
                'customer_id' => $customerId,
                'document_code' => $documentCode,
                'establishment_code' => $establishmentCode,
                'emission_point' => $emissionPoint,
                'guide_number' => $guideNumber,
                'is_negotiable' => $isNegotiable,
                'has_tip' => $hasTip,
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
                    'tax_code' => $item->taxCode,
                    'tax_amount' => $item->taxAmount(),
                    'ice_rate' => $item->iceRate,
                    'ice_code' => $item->iceCode,
                    'ice_amount' => $item->iceAmount(),
                    'subtotal' => $item->subtotal(),
                    'total' => $item->total(),
                ]);
            }

            foreach ($paymentMethods as $paymentMethod) {
                InvoicePaymentMethod::query()->create([
                    'invoice_id' => $invoice->id,
                    'method' => $paymentMethod['method'],
                    'value' => $paymentMethod['value'],
                    'term_value' => $paymentMethod['term_value'] ?? null,
                    'term_unit' => $paymentMethod['term_unit'] ?? null,
                ]);
            }

            foreach ($additionalFields as $additionalField) {
                InvoiceAdditionalField::query()->create([
                    'invoice_id' => $invoice->id,
                    'name' => $additionalField['name'],
                    'description' => $additionalField['description'],
                ]);
            }

            $totals = $this->totalsCalculator->calculate($invoice->fresh('items'));
            $invoice->update([
                'subtotal' => $totals->subtotal,
                'discount' => $totals->discount,
                'subtotal_15' => $totals->subtotal15,
                'subtotal_5' => $totals->subtotal5,
                'subtotal_special' => $totals->subtotalSpecial,
                'subtotal_zero' => $totals->subtotalZero,
                'subtotal_not_subject' => $totals->subtotalNotSubject,
                'subtotal_exempt' => $totals->subtotalExempt,
                'tax' => $totals->tax,
                'tax_15' => $totals->tax15,
                'tax_5' => $totals->tax5,
                'tax_special' => $totals->taxSpecial,
                'ice_total' => $totals->iceTotal,
                'tip_amount' => $totals->tipAmount,
                'total' => $totals->total,
            ]);

            return $invoice->fresh(['items', 'company', 'customer', 'paymentMethods', 'additionalFields']);
        });
    }
}
