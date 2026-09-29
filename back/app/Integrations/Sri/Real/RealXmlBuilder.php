<?php

namespace App\Integrations\Sri\Real;

use App\Enums\PaymentMethod;
use App\Enums\TaxCode;
use App\Integrations\Sri\Contracts\XmlBuilderInterface;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use RuntimeException;

/**
 * Builds a "factura" XML that follows the SRI Ficha Tecnica de Comprobantes
 * Electronicos v2.1.0 (schema version "2.1.0"), as opposed to DummyXmlBuilder
 * which only produces a loose approximation for local development.
 *
 * Tables referenced by code comments below (16, 17, 24, ...) are the ones
 * from that ficha tecnica.
 */
class RealXmlBuilder implements XmlBuilderInterface
{
    private const SCHEMA_VERSION = '2.1.0';

    /** Tabla 24: formas de pago. */
    private const FORMA_PAGO_CODES = [
        PaymentMethod::NoFinancialSystem->value => '01',
        PaymentMethod::DebtCompensation->value => '15',
        PaymentMethod::DebitCard->value => '16',
        PaymentMethod::ElectronicMoney->value => '17',
        PaymentMethod::PrepaidCard->value => '18',
        PaymentMethod::CreditCard->value => '19',
        // The SRI catalog has no dedicated "bank transfer" code; it falls
        // under 20 - "otros con utilizacion del sistema financiero".
        PaymentMethod::BankTransfer->value => '20',
        PaymentMethod::Other->value => '20',
        PaymentMethod::EndorsedSecurities->value => '21',
    ];

    public function __construct(private readonly AccessKeyGenerator $accessKeyGenerator)
    {
    }

    public function build(Invoice $invoice): string
    {
        $invoice->loadMissing(['company', 'customer', 'items', 'paymentMethods', 'additionalFields']);
        $company = $invoice->company;

        if (! $company->address) {
            throw new RuntimeException("Company #{$company->id} has no address set (required as dirMatriz/dirEstablecimiento).");
        }

        $xml = new \SimpleXMLElement('<factura/>');
        $xml->addAttribute('id', 'comprobante');
        $xml->addAttribute('version', self::SCHEMA_VERSION);

        $this->buildInfoTributaria($xml, $invoice, $company);
        $this->buildInfoFactura($xml, $invoice, $company);
        $this->buildDetalles($xml, $invoice);
        $this->buildInfoAdicional($xml, $invoice);

        return $xml->asXML() ?: '';
    }

    private function buildInfoTributaria(\SimpleXMLElement $xml, Invoice $invoice, $company): void
    {
        $info = $xml->addChild('infoTributaria');
        $info->addChild('ambiente', $company->environment === 'production' ? '2' : '1');
        $info->addChild('tipoEmision', '1');
        $info->addChild('razonSocial', $company->name);

        if ($company->trade_name) {
            $info->addChild('nombreComercial', $company->trade_name);
        }

        $info->addChild('ruc', $company->ruc);
        $info->addChild('claveAcceso', $this->accessKeyGenerator->generate($invoice));
        $info->addChild('codDoc', $invoice->document_code);
        $info->addChild('estab', $invoice->establishment_code);
        $info->addChild('ptoEmi', $invoice->emission_point);
        $info->addChild('secuencial', str_pad((string) $invoice->sequential, 9, '0', STR_PAD_LEFT));
        $info->addChild('dirMatriz', $company->address);
    }

    private function buildInfoFactura(\SimpleXMLElement $xml, Invoice $invoice, $company): void
    {
        $establishment = $company->establishments()->where('code', $invoice->establishment_code)->first();

        $info = $xml->addChild('infoFactura');
        $info->addChild('fechaEmision', $invoice->issue_date->format('d/m/Y'));
        $info->addChild('dirEstablecimiento', $establishment?->address ?: $company->address);
        $info->addChild('obligadoContabilidad', $company->requires_accounting ? 'SI' : 'NO');

        $customer = $invoice->customer;
        $info->addChild('tipoIdentificacionComprador', $customer->identification_type ?? '07');

        if ($invoice->guide_number) {
            $info->addChild('guiaRemision', $invoice->guide_number);
        }

        $info->addChild('razonSocialComprador', $customer->name ?? 'CONSUMIDOR FINAL');
        $info->addChild('identificacionComprador', $customer->identification_number ?? '9999999999999');

        if ($customer?->address) {
            $info->addChild('direccionComprador', $customer->address);
        }

        $info->addChild('totalSinImpuestos', $this->money($invoice->subtotal));
        $info->addChild('totalDescuento', $this->money($invoice->discount));

        $this->buildTotalConImpuestos($info, $invoice);

        $info->addChild('propina', $this->money($invoice->tip_amount));
        $info->addChild('importeTotal', $this->money($invoice->total));
        $info->addChild('moneda', $invoice->currency === 'USD' ? 'DOLAR' : $invoice->currency);

        $this->buildPagos($info, $invoice);
    }

    private function buildTotalConImpuestos(\SimpleXMLElement $info, Invoice $invoice): void
    {
        $totalConImpuestos = $info->addChild('totalConImpuestos');

        // Bucketed at the invoice level by InvoiceTotalsCalculator, one
        // <totalImpuesto> per IVA rate actually used on this invoice.
        $buckets = [
            ['rate' => 15.0, 'base' => $invoice->subtotal_15, 'value' => $invoice->tax_15],
            ['rate' => 5.0, 'base' => $invoice->subtotal_5, 'value' => $invoice->tax_5],
            ['rate' => null, 'base' => $invoice->subtotal_special, 'value' => $invoice->tax_special],
            ['rate' => 0.0, 'base' => $invoice->subtotal_zero, 'value' => 0.0],
            ['rate' => 0.0, 'base' => $invoice->subtotal_not_subject, 'value' => 0.0, 'code' => '6'],
            ['rate' => 0.0, 'base' => $invoice->subtotal_exempt, 'value' => 0.0, 'code' => '7'],
        ];

        $wroteAny = false;

        foreach ($buckets as $bucket) {
            if ((float) $bucket['base'] <= 0.0) {
                continue;
            }

            $wroteAny = true;
            $code = $bucket['code'] ?? $this->codigoPorcentajeForRate($bucket['rate']);

            $totalImpuesto = $totalConImpuestos->addChild('totalImpuesto');
            $totalImpuesto->addChild('codigo', '2'); // Tabla 16: IVA
            $totalImpuesto->addChild('codigoPorcentaje', $code);
            $totalImpuesto->addChild('baseImponible', $this->money($bucket['base']));
            $totalImpuesto->addChild('valor', $this->money($bucket['value']));
        }

        if (! $wroteAny) {
            // Every invoice must report at least one IVA bucket, even a
            // zero-value one, or the SRI rejects the document.
            $totalImpuesto = $totalConImpuestos->addChild('totalImpuesto');
            $totalImpuesto->addChild('codigo', '2');
            $totalImpuesto->addChild('codigoPorcentaje', '0');
            $totalImpuesto->addChild('baseImponible', $this->money(0));
            $totalImpuesto->addChild('valor', $this->money(0));
        }

        // ICE (codigo 3, Tabla 16) needs its own <totalImpuesto> per Tabla 18
        // product-category code, since a single invoice can legitimately mix
        // items from different ICE categories (e.g. cigarettes + sodas).
        $iceBuckets = [];
        foreach ($invoice->items as $item) {
            if ((float) $item->ice_amount <= 0.0) {
                continue;
            }

            if (! $item->ice_code) {
                throw new RuntimeException(
                    "InvoiceItem #{$item->id} has an ICE charge but no Tabla 18 ice_code configured."
                );
            }

            $iceBuckets[$item->ice_code]['base'] = ($iceBuckets[$item->ice_code]['base'] ?? 0.0) + (float) $item->subtotal;
            $iceBuckets[$item->ice_code]['value'] = ($iceBuckets[$item->ice_code]['value'] ?? 0.0) + (float) $item->ice_amount;
        }

        foreach ($iceBuckets as $code => $bucket) {
            $totalImpuesto = $totalConImpuestos->addChild('totalImpuesto');
            $totalImpuesto->addChild('codigo', '3'); // Tabla 16: ICE
            $totalImpuesto->addChild('codigoPorcentaje', (string) $code);
            $totalImpuesto->addChild('baseImponible', $this->money($bucket['base']));
            $totalImpuesto->addChild('valor', $this->money($bucket['value']));
        }
    }

    private function buildPagos(\SimpleXMLElement $info, Invoice $invoice): void
    {
        $pagos = $info->addChild('pagos');
        $methods = $invoice->paymentMethods;

        if ($methods->isEmpty()) {
            $pago = $pagos->addChild('pago');
            $pago->addChild('formaPago', '01');
            $pago->addChild('total', $this->money($invoice->total));

            return;
        }

        foreach ($methods as $method) {
            $pago = $pagos->addChild('pago');
            $pago->addChild('formaPago', self::FORMA_PAGO_CODES[$method->method->value] ?? '01');
            $pago->addChild('total', $this->money($method->value));

            if ($method->term_value) {
                $pago->addChild('plazo', (string) $method->term_value);
                $pago->addChild('unidadTiempo', $method->term_unit?->value ?? 'dias');
            }
        }
    }

    private function buildDetalles(\SimpleXMLElement $xml, Invoice $invoice): void
    {
        $detalles = $xml->addChild('detalles');

        foreach ($invoice->items as $item) {
            $detalle = $detalles->addChild('detalle');
            $detalle->addChild('codigoPrincipal', $item->code);
            $detalle->addChild('descripcion', $item->name);
            $detalle->addChild('cantidad', $this->money($item->quantity));
            $detalle->addChild('precioUnitario', $this->money($item->unit_price));
            $detalle->addChild('descuento', $this->money($item->discount));
            $detalle->addChild('precioTotalSinImpuesto', $this->money($item->subtotal));

            $this->buildItemImpuestos($detalle, $item);
        }
    }

    private function buildItemImpuestos(\SimpleXMLElement $detalle, InvoiceItem $item): void
    {
        $impuestos = $detalle->addChild('impuestos');
        $impuesto = $impuestos->addChild('impuesto');

        $code = $item->tax_code === TaxCode::NotSubject
            ? '6'
            : ($item->tax_code === TaxCode::Exempt ? '7' : $this->codigoPorcentajeForRate((float) $item->tax_rate));

        // ICE widens the IVA taxable base (InvoiceItemData::taxAmount()), so
        // the declared baseImponible here must include it too, or valor
        // won't match baseImponible * tarifa as the SRI expects.
        $ivaBase = (float) $item->subtotal + (float) $item->ice_amount;

        $impuesto->addChild('codigo', '2'); // Tabla 16: IVA
        $impuesto->addChild('codigoPorcentaje', $code);
        $impuesto->addChild('tarifa', $this->money($item->tax_rate));
        $impuesto->addChild('baseImponible', $this->money($ivaBase));
        $impuesto->addChild('valor', $this->money($item->tax_amount));

        if ((float) $item->ice_amount > 0.0) {
            if (! $item->ice_code) {
                throw new RuntimeException(
                    "InvoiceItem #{$item->id} has an ICE charge but no Tabla 18 ice_code configured."
                );
            }

            $iceImpuesto = $impuestos->addChild('impuesto');
            $iceImpuesto->addChild('codigo', '3'); // Tabla 16: ICE
            $iceImpuesto->addChild('codigoPorcentaje', $item->ice_code);
            $iceImpuesto->addChild('tarifa', $this->money($item->ice_rate));
            $iceImpuesto->addChild('baseImponible', $this->money($item->subtotal));
            $iceImpuesto->addChild('valor', $this->money($item->ice_amount));
        }
    }

    private function buildInfoAdicional(\SimpleXMLElement $xml, Invoice $invoice): void
    {
        if ($invoice->additionalFields->isEmpty()) {
            return;
        }

        $infoAdicional = $xml->addChild('infoAdicional');

        foreach ($invoice->additionalFields->take(15) as $field) {
            $campo = $infoAdicional->addChild('campoAdicional', $field->description);
            $campo->addAttribute('nombre', $field->name);
        }
    }

    /**
     * Tabla 17: tarifa del IVA. Falls back to codigo 8 ("IVA diferenciado")
     * for any rate outside the catalog, using the real rate as tarifa.
     */
    private function codigoPorcentajeForRate(?float $rate): string
    {
        return match ($rate) {
            0.0 => '0',
            12.0 => '2',
            14.0 => '3',
            15.0 => '4',
            5.0 => '5',
            13.0 => '10',
            default => '8',
        };
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
