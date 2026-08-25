<?php

namespace App\Jobs\Billing;

use App\Enums\InvoiceStatus;
use App\Integrations\Sri\Contracts\XmlBuilderInterface;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Services\Billing\InvoiceStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class BuildXmlJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(private readonly int $invoiceId)
    {
    }

    public function handle(XmlBuilderInterface $xmlBuilder, InvoiceStateService $stateService): void
    {
        $invoice = Invoice::query()->with(['company', 'customer', 'items'])->findOrFail($this->invoiceId);

        if ($invoice->status !== InvoiceStatus::Processing) {
            return;
        }

        $xml = $xmlBuilder->build($invoice);
        $path = "invoices/{$invoice->company_id}/{$invoice->id}/generated.xml";
        Storage::disk('local')->put($path, $xml);

        InvoiceDocument::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            ['xml_generated_path' => $path]
        );

        $stateService->setStatus($invoice, InvoiceStatus::XmlBuilt);

        SignXadesJob::dispatch($invoice->id);
    }

    public function failed(\Throwable $exception): void
    {
        $invoice = Invoice::query()->find($this->invoiceId);

        if (! $invoice) {
            return;
        }

        app(InvoiceStateService::class)->setStatus($invoice, InvoiceStatus::Failed, [
            'job' => self::class,
            'error' => $exception->getMessage(),
        ]);
    }
}
