<?php

namespace App\Jobs\Billing;

use App\Enums\InvoiceStatus;
use App\Integrations\Sri\Contracts\SriClientInterface;
use App\Integrations\Sri\Enums\SriAuthorizationStatus;
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

    /**
     * How many times we re-query the SRI while it reports the comprobante is
     * still being processed (PPR/EN PROCESAMIENTO) before giving up. This is
     * a business-level bound on non-exceptional "not resolved yet"
     * responses -- unrelated to $tries/$backoff above, which only govern
     * retries after a thrown exception (SoapFault, malformed response,
     * etc), since a pending response is not an error and never throws.
     */
    private const MAX_PENDING_ATTEMPTS = 5;

    /**
     * Delay between pending re-queries. The SRI gives no documented SLA for
     * how long a comprobante can stay in PPR, so this is a conservative,
     * arbitrary interval -- not a value derived from any SRI spec.
     */
    private const PENDING_RETRY_DELAY_SECONDS = 45;

    public function __construct(
        private readonly int $invoiceId,
        private readonly string $accessKey,
        private readonly int $attempt = 1,
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

        if ($response->status === SriAuthorizationStatus::Authorized) {
            $document->update([
                'xml_authorized_path' => $document->xml_signed_path ?: $document->xml_generated_path,
            ]);

            $invoice->update([
                'sri_authorization_number' => $response->authorizationNumber,
                'sri_response_payload' => $payload,
            ]);

            $stateService->setStatus($invoice, InvoiceStatus::Authorized, [
                'messages' => $response->messages,
                'message_details' => $response->messageDetails,
            ]);

            return;
        }

        if ($response->status === SriAuthorizationStatus::Rejected) {
            $invoice->update([
                'sri_response_payload' => $payload,
            ]);

            $stateService->setStatus($invoice, InvoiceStatus::Rejected, [
                'messages' => $response->messages,
                'message_details' => $response->messageDetails,
            ]);

            return;
        }

        // Pending or Unknown: the SRI has not given a definitive answer, so
        // this is never treated as a rejection. The invoice stays in
        // SentReception (reused as the "awaiting authorization" state -- no
        // dedicated status exists for this) and we re-query a bounded
        // number of times before giving up.
        $invoice->update([
            'sri_response_payload' => $payload,
        ]);

        if ($this->attempt >= self::MAX_PENDING_ATTEMPTS) {
            $stateService->setStatus($invoice, InvoiceStatus::Failed, [
                'job' => self::class,
                'reason' => 'sri_authorization_not_resolved_after_max_attempts',
                'sri_status' => $response->status->value,
                'attempts' => $this->attempt,
                'messages' => $response->messages,
                'message_details' => $response->messageDetails,
            ]);

            return;
        }

        $invoice->events()->create([
            'event' => InvoiceStatus::SentReception->value,
            'payload' => [
                'stage' => 'authorization_pending',
                'sri_status' => $response->status->value,
                'attempt' => $this->attempt,
                'messages' => $response->messages,
                'message_details' => $response->messageDetails,
            ],
        ]);

        self::dispatch($this->invoiceId, $this->accessKey, $this->attempt + 1)
            ->delay(now()->addSeconds(self::PENDING_RETRY_DELAY_SECONDS));
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
