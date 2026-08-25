<?php

use Illuminate\Foundation\Inspiring;
use App\DTOs\Billing\InvoiceItemData;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Billing\InvoiceDraftService;
use App\Services\Billing\InvoiceEmissionService;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('billing:demo-draft', function (InvoiceDraftService $draftService) {
    $company = Company::query()->firstOrCreate(
        ['ruc' => '0999999999001'],
        [
            'name' => 'Empresa Demo',
            'trade_name' => 'Demo',
            'environment' => 'test',
            'sri_signing_enabled' => false,
            'sri_submission_enabled' => false,
        ],
    );

    $customer = Customer::query()->firstOrCreate(
        [
            'company_id' => $company->id,
            'identification_number' => '0912345678',
        ],
        [
            'name' => 'Cliente Demo',
            'identification_type' => '05',
            'email' => 'cliente@example.com',
        ],
    );

    $invoice = $draftService->create(
        companyId: $company->id,
        customerId: $customer->id,
        items: [
            new InvoiceItemData(
                code: 'P-001',
                name: 'Servicio Demo',
                quantity: 1,
                unitPrice: 100,
                discount: 0,
                taxRate: 15,
            ),
        ],
    );

    $this->info("Draft creado: #{$invoice->id} secuencial {$invoice->sequential} total {$invoice->total}");
})->purpose('Crea una factura draft de ejemplo multiempresa');

Artisan::command('billing:emit {invoiceId}', function (int $invoiceId, InvoiceEmissionService $emissionService) {
    $invoice = Invoice::query()->with('company')->findOrFail($invoiceId);
    $pipeline = $emissionService->dispatch($invoice);

    $this->info("Pipeline despachado para factura {$invoice->id}");
    $this->line('signing_enabled='.($pipeline->signingEnabled ? 'true' : 'false'));
    $this->line('submission_enabled='.($pipeline->submissionEnabled ? 'true' : 'false'));
})->purpose('Despacha pipeline XML->(Sign)->(Send/Simulate) para una factura');
