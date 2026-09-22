<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Integrations\Sri\DTOs\SriAuthorizationResponseDTO;
use App\Integrations\Sri\Enums\SriAuthorizationStatus;
use App\Jobs\Billing\BuildXmlJob;
use App\Jobs\Billing\CheckAuthorizationJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceDocument;
use App\Services\Billing\InvoiceStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\Support\FakeAuthorizationSriClient;
use Tests\TestCase;

/**
 * Exercises CheckAuthorizationJob's branching on the SRI's three real
 * authorization outcomes (AUTORIZADO / NO AUTORIZADO / PPR-EN PROCESAMIENTO)
 * directly against handle(), bypassing the queue so each scenario can be
 * asserted in isolation without the sync queue driver cascading through a
 * bounded retry chain in a single call.
 */
class CheckAuthorizationPendingTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS_KEY = '1234567890123456789012345678901234567890123456789';

    private function makeInvoiceAwaitingAuthorization(): Invoice
    {
        $company = Company::query()->create([
            'name' => 'Acme SA',
            'ruc' => '0999999999001',
            'environment' => 'test',
        ]);

        $invoice = Invoice::query()->create([
            'company_id' => $company->id,
            'document_code' => '01',
            'sequential' => 1,
            'issue_date' => now()->toDateString(),
            'status' => InvoiceStatus::SentReception,
            'access_key' => self::ACCESS_KEY,
            'total' => 10,
        ]);

        InvoiceDocument::query()->create([
            'invoice_id' => $invoice->id,
            'xml_generated_path' => "invoices/{$company->id}/{$invoice->id}/generated.xml",
            'xml_signed_path' => "invoices/{$company->id}/{$invoice->id}/signed.xml",
        ]);

        return $invoice;
    }

    private function runJob(Invoice $invoice, ?SriAuthorizationResponseDTO $response, int $attempt = 1, ?\Throwable $exception = null): void
    {
        $job = new CheckAuthorizationJob($invoice->id, self::ACCESS_KEY, $attempt);
        $job->handle(new FakeAuthorizationSriClient($response, $exception), app(InvoiceStateService::class));
    }

    private function authorized(): SriAuthorizationResponseDTO
    {
        return new SriAuthorizationResponseDTO(
            status: SriAuthorizationStatus::Authorized,
            authorized: true,
            authorizationNumber: self::ACCESS_KEY,
            messages: ['AUTORIZADO'],
        );
    }

    private function rejected(): SriAuthorizationResponseDTO
    {
        return new SriAuthorizationResponseDTO(
            status: SriAuthorizationStatus::Rejected,
            authorized: false,
            authorizationNumber: null,
            messages: ['RUC no existe'],
        );
    }

    private function pending(): SriAuthorizationResponseDTO
    {
        return new SriAuthorizationResponseDTO(
            status: SriAuthorizationStatus::Pending,
            authorized: false,
            authorizationNumber: null,
            messages: [],
        );
    }

    public function test_autorizado_marks_invoice_authorized(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->authorized());

        $this->assertSame(InvoiceStatus::Authorized, $invoice->fresh()->status);
    }

    public function test_no_autorizado_marks_invoice_rejected(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->rejected());

        $this->assertSame(InvoiceStatus::Rejected, $invoice->fresh()->status);
    }

    public function test_ppr_keeps_invoice_in_sent_reception(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->pending(), attempt: 1);

        $this->assertSame(InvoiceStatus::SentReception, $invoice->fresh()->status);
    }

    public function test_ppr_schedules_a_follow_up_check_with_a_delay(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->pending(), attempt: 1);

        Bus::assertDispatched(CheckAuthorizationJob::class, fn (CheckAuthorizationJob $job) => $job->delay !== null);
    }

    public function test_ppr_never_restarts_the_pipeline_from_scratch(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->pending(), attempt: 1);

        Bus::assertNotDispatched(BuildXmlJob::class);
    }

    public function test_access_key_is_unchanged_after_a_ppr_response(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->pending(), attempt: 1);

        $this->assertSame(self::ACCESS_KEY, $invoice->fresh()->access_key);
    }

    public function test_ppr_reschedules_again_just_before_the_attempt_limit(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->pending(), attempt: 4);

        $this->assertSame(InvoiceStatus::SentReception, $invoice->fresh()->status);
        Bus::assertDispatched(CheckAuthorizationJob::class);
    }

    public function test_ppr_exhausts_bounded_attempts_and_marks_failed_without_further_dispatch(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();
        $this->runJob($invoice, $this->pending(), attempt: 5);

        $this->assertSame(InvoiceStatus::Failed, $invoice->fresh()->status);
        Bus::assertNotDispatched(CheckAuthorizationJob::class);
    }

    public function test_technical_exception_bypasses_ppr_handling_and_leaves_status_unchanged(): void
    {
        $invoice = $this->makeInvoiceAwaitingAuthorization();

        Bus::fake();

        $this->expectException(RuntimeException::class);

        try {
            $this->runJob($invoice, null, attempt: 1, exception: new RuntimeException('SRI authorization WS call failed: connection timed out'));
        } finally {
            // The exception must propagate uncaught -- it is Laravel's job
            // retry ($tries/$backoff), not this job's PPR logic, that is
            // responsible for handling it. Confirm handle() did not
            // swallow it and silently change the invoice's state.
            $this->assertSame(InvoiceStatus::SentReception, $invoice->fresh()->status);
            Bus::assertNotDispatched(CheckAuthorizationJob::class);
        }
    }
}
