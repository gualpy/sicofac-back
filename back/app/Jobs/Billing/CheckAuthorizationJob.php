<?php

namespace App\Jobs\Billing;

use App\Enums\InvoiceStatus;
use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Services\Billing\InvoiceStateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckAuthorizationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(
        private readonly int $invoiceId,
        private readonly string $accessKey,
    ) {
    }

    public function handle(SriClientInterface $sriClient, InvoiceStateService $stateService): void
    {
        $invoice = Invoice::query()->with('company')->findOrFail($this->invoiceId);

        if ($invoice->status !== InvoiceStatus::SentReception) {
            return;
        }

        $document = InvoiceDocument::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $response = $sriClient->checkAuthorization($this->accessKey, $invoice->company->environment);

        $payload = $invoice->sri_response_payload ?? [];
        $payload['authorization'] = $response->payload;

        if ($response->authorized) {
            $document->update([
                'xml_authorized_path' => $document->xml_signed_path ?: $document->xml_generated_path,
            ]);

            $invoice->update([
                'sri_authorization_number' => $response->authorizationNumber,
                'sri_response_payload' => $payload,
            ]);

            $stateService->setStatus($invoice, InvoiceStatus::Authorized, [
                'messages' => $response->messages,
            ]);

            return;
        }

        $invoice->update([
            'sri_response_payload' => $payload,
        ]);

        $stateService->setStatus($invoice, InvoiceStatus::Rejected, [
            'messages' => $response->messages,
        ]);
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
