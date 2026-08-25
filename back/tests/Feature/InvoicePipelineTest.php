<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Enums\InvoiceStatus;
use App\Integrations\Sri\Contracts\XmlBuilderInterface;
use App\Jobs\Billing\BuildXmlJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoiceStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class InvoicePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_registers_intermediate_states_and_finishes_authorized(): void
    {
        [$company, $invoiceId] = $this->createDraftInvoiceForOwner();

        $this->postJson("/api/companies/{$company->id}/invoices/{$invoiceId}/emit")
            ->assertOk();

        $invoice = Invoice::query()->with('events')->findOrFail($invoiceId);

        $this->assertSame(InvoiceStatus::Authorized, $invoice->status);
        $this->assertSame(
            ['xml_built', 'signed', 'sent_reception', 'authorized'],
            $invoice->events->pluck('event')->all()
        );
    }

    public function test_emit_is_blocked_for_non_draft_status(): void
    {
        [$company, $invoiceId] = $this->createDraftInvoiceForOwner();

        Invoice::query()->whereKey($invoiceId)->update(['status' => InvoiceStatus::Processing]);

        $this->postJson("/api/companies/{$company->id}/invoices/{$invoiceId}/emit")
            ->assertStatus(409);
    }

    public function test_build_job_retry_config_is_defined(): void
    {
        $job = new BuildXmlJob(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([5, 15, 30], $job->backoff);
    }

    public function test_build_job_can_continue_after_transient_builder_failure(): void
    {
        [$company, $invoiceId] = $this->createDraftInvoiceForOwner();
        Invoice::query()->whereKey($invoiceId)->update(['status' => InvoiceStatus::Processing]);

        $job = new BuildXmlJob($invoiceId);

        $flakyBuilder = new class implements XmlBuilderInterface {
            public int $calls = 0;

            public function build(Invoice $invoice): string
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new RuntimeException('temporary XML error');
                }

                return '<factura><id>comprobante</id></factura>';
            }
        };

        try {
            $job->handle($flakyBuilder, app(InvoiceStateService::class));
            $this->fail('First attempt should fail.');
        } catch (RuntimeException) {
            // expected transient failure
        }

        $this->assertSame(InvoiceStatus::Processing, Invoice::query()->findOrFail($invoiceId)->status);

        $job->handle($flakyBuilder, app(InvoiceStateService::class));

        $this->assertSame(2, $flakyBuilder->calls);
        $this->assertSame(InvoiceStatus::Authorized, Invoice::query()->findOrFail($invoiceId)->status);
    }

    /**
     * @return array{Company, int}
     */
    private function createDraftInvoiceForOwner(): array
    {
        $owner = User::query()->create([
            'name' => 'Pipeline Owner',
            'email' => 'pipeline-owner@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Pipeline Co',
            'ruc' => '0999999999111',
            'environment' => 'test',
        ]);

        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($owner);

        $invoiceId = $this->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [[
                'code' => 'SKU-P-1',
                'name' => 'Pipeline Item',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 15,
            ]],
        ])->assertCreated()->json('id');

        return [$company, $invoiceId];
    }
}
