<?php

namespace App\Jobs\Billing;

use App\Enums\InvoiceStatus;
use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Services\Billing\InvoiceStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class SendReceptionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(private readonly int $invoiceId)
    {
    }

    public function handle(SriClientInterface $sriClient, InvoiceStateService $stateService): void
    {
        $invoice = Invoice::query()->with('company')->findOrFail($this->invoiceId);

        if ($invoice->status !== InvoiceStatus::Signed) {
            return;
        }

        $document = InvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $signedXml = (string) Storage::disk('local')->get($document->xml_signed_path ?: $document->xml_generated_path);

        $response = $sriClient->sendToReception($signedXml, $invoice->company->environment);

        if (! $response->success) {
            $stateService->setStatus($invoice, InvoiceStatus::Rejected, [
                'stage' => 'reception',
                'messages' => $response->messages,
                'message_details' => $response->messageDetails,
                'payload' => $response->payload,
            ]);

            return;
        }

        $stateService->setStatus($invoice, InvoiceStatus::SentReception, [
            'access_key' => $response->accessKey,
            'messages' => $response->messages,
            'message_details' => $response->messageDetails,
        ]);

        $invoice->update([
            'access_key' => $response->accessKey,
            'sri_response_payload' => [
                'reception' => $response->payload,
            ],
        ]);

        CheckAuthorizationJob::dispatch($invoice->id, (string) $response->accessKey);
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
