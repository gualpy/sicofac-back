<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentTermUnit;
use App\Models\Company;
use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Picqer\Barcode\BarcodeGenerator;
use Picqer\Barcode\BarcodeGeneratorHTML;
use RuntimeException;

/**
 * Builds the RIDE (Representacion Impresa del Documento Electronico): the
 * human-readable PDF an issuer hands to the buyer. It is generated locally
 * from the already-authorized invoice -- it is never sent to the SRI, the
 * signed XML is what constitutes the legal document.
 */
class RideGenerator
{
    public function generate(Invoice $invoice): string
    {
        if ($invoice->status !== InvoiceStatus::Authorized) {
            throw new RuntimeException('RIDE can only be generated for an authorized invoice.');
        }

        $invoice->loadMissing(['company', 'customer', 'items', 'paymentMethods', 'additionalFields']);
        $company = $invoice->company;

        $barcodeGenerator = new BarcodeGeneratorHTML();
        $barcodeHtml = $barcodeGenerator->getBarcode(
            (string) $invoice->access_key,
            BarcodeGenerator::TYPE_CODE_128,
            widthFactor: 1,
            height: 40,
        );

        $html = view('invoices.ride', [
            'invoice' => $invoice,
            'company' => $company,
            'customerName' => $invoice->customer->name ?? 'CONSUMIDOR FINAL',
            'customerIdentification' => $invoice->customer->identification_number ?? '9999999999999',
            'customerAddress' => $invoice->customer->address ?? null,
            'customerPhone' => $invoice->customer->phone ?? null,
            'customerEmail' => $invoice->customer->email ?? null,
            'barcodeHtml' => $barcodeHtml,
            'logoBase64' => $this->logoAsDataUri($company),
            'paymentMethodLabels' => $this->paymentMethodLabels(),
            'paymentTermUnitLabels' => $this->paymentTermUnitLabels(),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function logoAsDataUri(Company $company): ?string
    {
        // Embedding an image forces dompdf to decode it through GD (even
        // from a base64 data URI); skip the logo rather than fail the whole
        // RIDE when GD isn't installed -- the barcode itself is plain HTML
        // and unaffected either way.
        if (! extension_loaded('gd')) {
            return null;
        }

        if (! $company->logo_path || ! Storage::disk('local')->exists($company->logo_path)) {
            return null;
        }

        $contents = Storage::disk('local')->get($company->logo_path);
        $mime = Storage::disk('local')->mimeType($company->logo_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    /**
     * @return array<string, string>
     */
    private function paymentMethodLabels(): array
    {
        return [
            PaymentMethod::NoFinancialSystem->value => 'SIN UTILIZACION DEL SISTEMA FINANCIERO',
            PaymentMethod::DebtCompensation->value => 'COMPENSACION DE DEUDAS',
            PaymentMethod::DebitCard->value => 'TARJETA DE DEBITO',
            PaymentMethod::ElectronicMoney->value => 'DINERO ELECTRONICO',
            PaymentMethod::PrepaidCard->value => 'TARJETA PREPAGO',
            PaymentMethod::CreditCard->value => 'TARJETA DE CREDITO',
            PaymentMethod::BankTransfer->value => 'TRANSFERENCIA BANCARIA',
            PaymentMethod::Other->value => 'OTROS',
            PaymentMethod::EndorsedSecurities->value => 'ENDOSO DE TITULOS',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function paymentTermUnitLabels(): array
    {
        return [
            PaymentTermUnit::Days->value => 'dias',
            PaymentTermUnit::Months->value => 'meses',
            PaymentTermUnit::Years->value => 'anios',
        ];
    }
}
