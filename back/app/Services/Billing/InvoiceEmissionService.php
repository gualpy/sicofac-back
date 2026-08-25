<?php

namespace App\Services\Billing;

use App\DTOs\Billing\InvoicePipelineData;
use App\Enums\InvoiceStatus;
use App\Jobs\Billing\BuildXmlJob;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvoiceEmissionService
{
    public function __construct(
        private readonly InvoiceTotalsCalculator $totalsCalculator,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function dispatch(Invoice $invoice): InvoicePipelineData
    {
        return DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()
                ->with(['company', 'items'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            if ($locked->status !== InvoiceStatus::Draft) {
                throw new DomainException('Invoice cannot be issued because it is not in DRAFT status.');
            }

            $before = $locked->toArray();

            $totals = $this->totalsCalculator->calculate($locked);
            $locked->update([
                'subtotal' => $totals->subtotal,
                'discount' => $totals->discount,
                'tax' => $totals->tax,
                'total' => $totals->total,
                'status' => InvoiceStatus::Processing,
                'issue_requested_at' => now(),
            ]);

            BuildXmlJob::dispatch($locked->id);

            $this->auditLogger->log(
                'invoice.issue.requested',
                $locked,
                $before,
                $locked->fresh()->toArray(),
                ['pipeline' => [
                    'driver' => config('sri.driver'),
                ]]
            );

            return new InvoicePipelineData(
                invoiceId: $locked->id,
                signingEnabled: true,
                submissionEnabled: true,
            );
        });
    }
}
