<?php

namespace App\Jobs\Billing;

use App\Enums\InvoiceStatus;
use App\Integrations\Sri\Contracts\XadesSignerInterface;
use App\Models\CompanyCertificate;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Services\Billing\InvoiceStateService;
use App\Services\Certificates\CompanyCertificateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class SignXadesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(private readonly int $invoiceId)
    {
    }

    public function handle(
        XadesSignerInterface $signer,
        CompanyCertificateService $certificateService,
        InvoiceStateService $stateService,
    ): void {
        $invoice = Invoice::query()->with('company')->findOrFail($this->invoiceId);

        if ($invoice->status !== InvoiceStatus::XmlBuilt) {
            return;
        }

        $document = InvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $xml = (string) Storage::disk('local')->get($document->xml_generated_path);

        $certificate = $certificateService->getActiveCertificate($invoice->company)
            ?? new CompanyCertificate([
                'company_id' => $invoice->company_id,
                'version' => 0,
                'scope_type' => 'company',
                'scope_id' => null,
                'path' => 'dummy://certificate',
                'is_active' => false,
                'password_encrypted' => '',
                'uploaded_at' => now(),
            ]);

        $signedXml = $signer->sign($xml, $certificate);
        $signedPath = "invoices/{$invoice->company_id}/{$invoice->id}/signed.xml";
        Storage::disk('local')->put($signedPath, $signedXml);

        $document->update([
            'xml_signed_path' => $signedPath,
        ]);

        $stateService->setStatus($invoice, InvoiceStatus::Signed, [
            'certificate_version' => $certificate->version ?: null,
        ]);

        SendReceptionJob::dispatch($invoice->id);
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
