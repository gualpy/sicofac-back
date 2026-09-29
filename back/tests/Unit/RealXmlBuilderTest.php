<?php

namespace Tests\Unit;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\TaxCode;
use App\Integrations\Sri\Real\RealXmlBuilder;
use App\Models\Company;
use App\Models\CompanyEstablishment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceAdditionalField;
use App\Models\InvoiceItem;
use App\Models\InvoicePaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RealXmlBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(array $overrides = []): Invoice
    {
        $company = Company::query()->create([
            'name' => 'Acme SA',
            'trade_name' => 'Acme',
            'ruc' => '0999999999001',
            'environment' => 'test',
            'address' => 'Av. Principal 123',
            'requires_accounting' => true,
        ]);

        CompanyEstablishment::query()->create([
            'company_id' => $company->id,
            'code' => '001',
            'name' => 'Matriz',
            'address' => 'Av. Principal 123',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'company_id' => $company->id,
            'name' => 'Cliente Uno',
            'identification_type' => '05',
            'identification_number' => '0912345678',
            'address' => 'Guayaquil',
        ]);

        $invoice = Invoice::query()->create(array_merge([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'document_code' => '01',
            'establishment_code' => '001',
            'emission_point' => '001',
            'sequential' => 1,
            'issue_date' => '2026-08-29',
            'status' => InvoiceStatus::Processing,
            'currency' => 'USD',
            'subtotal' => 40.00,
            'discount' => 0,
            'subtotal_15' => 40.00,
            'tax_15' => 6.00,
            'tax' => 6.00,
            'total' => 46.00,
        ], $overrides));

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'code' => 'SKU-1',
            'name' => 'Producto A',
            'quantity' => 2,
            'unit_price' => 20,
            'discount' => 0,
            'tax_rate' => 15,
            'tax_code' => TaxCode::Rate15,
            'tax_amount' => 6.00,
            'subtotal' => 40.00,
            'total' => 46.00,
        ]);

        InvoicePaymentMethod::query()->create([
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::CreditCard,
            'value' => 46.00,
        ]);

        InvoiceAdditionalField::query()->create([
            'invoice_id' => $invoice->id,
            'name' => 'Email',
            'description' => 'cliente@example.com',
        ]);

        return $invoice->fresh();
    }

    public function test_it_builds_a_well_formed_v2_1_0_factura_xml(): void
    {
        $invoice = $this->makeInvoice();

        $xml = new \SimpleXMLElement(app(RealXmlBuilder::class)->build($invoice));

        $this->assertSame('2.1.0', (string) $xml['version']);
        $this->assertSame('comprobante', (string) $xml['id']);

        $this->assertSame('1', (string) $xml->infoTributaria->ambiente);
        $this->assertSame('0999999999001', (string) $xml->infoTributaria->ruc);
        $this->assertSame('001', (string) $xml->infoTributaria->estab);
        $this->assertSame('000000001', (string) $xml->infoTributaria->secuencial);

        $claveAcceso = (string) $xml->infoTributaria->claveAcceso;
        $this->assertSame(49, strlen($claveAcceso));
        $this->assertMatchesRegularExpression('/^\d{49}$/', $claveAcceso);

        $this->assertSame('Cliente Uno', (string) $xml->infoFactura->razonSocialComprador);
        $this->assertSame('40.00', (string) $xml->infoFactura->totalSinImpuestos);
        $this->assertSame('46.00', (string) $xml->infoFactura->importeTotal);

        $totalImpuesto = $xml->infoFactura->totalConImpuestos->totalImpuesto;
        $this->assertSame('2', (string) $totalImpuesto->codigo);
        $this->assertSame('4', (string) $totalImpuesto->codigoPorcentaje); // Tabla 17: 15% => codigo 4
        $this->assertSame('6.00', (string) $totalImpuesto->valor);

        $this->assertSame('19', (string) $xml->infoFactura->pagos->pago->formaPago); // Tabla 24: tarjeta credito
        $this->assertSame('46.00', (string) $xml->infoFactura->pagos->pago->total);

        $detalle = $xml->detalles->detalle;
        $this->assertSame('SKU-1', (string) $detalle->codigoPrincipal);
        $this->assertSame('4', (string) $detalle->impuestos->impuesto->codigoPorcentaje);

        $this->assertSame('Email', (string) $xml->infoAdicional->campoAdicional['nombre']);
    }

    public function test_it_refuses_to_build_when_company_has_no_address(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->company()->update(['address' => null]);

        $this->expectException(RuntimeException::class);

        app(RealXmlBuilder::class)->build($invoice->fresh());
    }

    public function test_it_refuses_to_build_when_an_item_has_ice_without_a_configured_code(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->items()->first()->update(['ice_amount' => 5.00]);

        $this->expectException(RuntimeException::class);

        app(RealXmlBuilder::class)->build($invoice->fresh());
    }

    public function test_it_builds_ice_impuesto_nodes_when_ice_code_is_configured(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->items()->first()->update([
            'ice_rate' => 50,
            'ice_code' => '3072',
            'ice_amount' => 20.00, // subtotal(40) * 50%
            'tax_amount' => 9.00, // (subtotal(40) + ice(20)) * 15%
        ]);

        $xml = new \SimpleXMLElement(app(RealXmlBuilder::class)->build($invoice->fresh()));

        $impuestos = $xml->detalles->detalle->impuestos->impuesto;
        $this->assertCount(2, $impuestos);

        $iva = $impuestos[0];
        $this->assertSame('2', (string) $iva->codigo);
        $this->assertSame('60.00', (string) $iva->baseImponible); // subtotal + ice_amount
        $this->assertSame('9.00', (string) $iva->valor);

        $ice = $impuestos[1];
        $this->assertSame('3', (string) $ice->codigo);
        $this->assertSame('3072', (string) $ice->codigoPorcentaje);
        $this->assertSame('40.00', (string) $ice->baseImponible); // plain subtotal
        $this->assertSame('20.00', (string) $ice->valor);

        $iceTotal = null;
        foreach ($xml->infoFactura->totalConImpuestos->totalImpuesto as $totalImpuesto) {
            if ((string) $totalImpuesto->codigo === '3') {
                $iceTotal = $totalImpuesto;
            }
        }

        $this->assertNotNull($iceTotal);
        $this->assertSame('3072', (string) $iceTotal->codigoPorcentaje);
        $this->assertSame('40.00', (string) $iceTotal->baseImponible);
        $this->assertSame('20.00', (string) $iceTotal->valor);
    }
}
