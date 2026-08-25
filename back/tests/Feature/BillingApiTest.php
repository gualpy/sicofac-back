<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceDocument;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_draft_and_emits_authorized_invoice_in_dummy_mode(): void
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'password',
        ]);
        Sanctum::actingAs($user);

        $companyResponse = $this->postJson('/api/companies', [
            'name' => 'Acme SA',
            'trade_name' => 'Acme',
            'ruc' => '0999999999001',
            'environment' => 'test',
            'sri_signing_enabled' => false,
            'sri_submission_enabled' => false,
        ])->assertCreated();

        $companyId = $companyResponse->json('id');

        $customerResponse = $this->postJson("/api/companies/{$companyId}/customers", [
            'name' => 'Cliente Uno',
            'identification_type' => '05',
            'identification_number' => '0912345678',
            'email' => 'cliente@example.com',
            'address' => 'Guayaquil',
        ])->assertCreated();

        $customerId = $customerResponse->json('id');

        $this->postJson("/api/companies/{$companyId}/products", [
            'code' => 'SKU-1',
            'name' => 'Producto A',
            'unit_price' => 20,
            'tax_rate' => 15,
            'is_active' => true,
        ])->assertCreated();

        $invoiceResponse = $this->postJson("/api/companies/{$companyId}/invoices", [
            'customer_id' => $customerId,
            'document_code' => '01',
            'items' => [
                [
                    'code' => 'SKU-1',
                    'name' => 'Producto A',
                    'quantity' => 2,
                    'unit_price' => 20,
                    'discount' => 0,
                    'tax_rate' => 15,
                ],
            ],
        ])->assertCreated();

        $invoiceId = $invoiceResponse->json('id');

        $this->postJson("/api/companies/{$companyId}/invoices/{$invoiceId}/emit")
            ->assertOk()
            ->assertJson([
                'invoice_id' => $invoiceId,
                'signing_enabled' => true,
                'submission_enabled' => true,
            ]);

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $document = InvoiceDocument::query()->where('invoice_id', $invoiceId)->firstOrFail();

        $this->assertSame(InvoiceStatus::Authorized, $invoice->status);
        $this->assertEquals(46.00, (float) $invoice->total);
        $this->assertNotNull($document->xml_generated_path);
        $this->assertNotNull($document->xml_signed_path);
        $this->assertNotNull($document->xml_authorized_path);
        $this->assertTrue(Storage::disk('local')->exists($document->xml_generated_path));
        $this->assertTrue(Storage::disk('local')->exists($document->xml_signed_path));
        $this->assertTrue(Storage::disk('local')->exists($document->xml_authorized_path));
    }
}
