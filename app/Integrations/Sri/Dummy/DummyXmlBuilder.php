<?php

namespace App\Integrations\Sri\Dummy;

use App\Integrations\Sri\Contracts\XmlBuilderInterface;
use App\Models\Invoice;

class DummyXmlBuilder implements XmlBuilderInterface
{
    public function build(Invoice $invoice): string
    {
        $invoice->loadMissing(['company', 'customer', 'items']);

        $xml = new \SimpleXMLElement('<factura/>');
        $xml->addChild('id', 'comprobante');
        $xml->addChild('version', '1.0.0');

        $taxInfo = $xml->addChild('infoTributaria');
        $taxInfo->addChild('ruc', $invoice->company->ruc);
        $taxInfo->addChild('codDoc', $invoice->document_code);
        $taxInfo->addChild('estab', $invoice->establishment_code);
        $taxInfo->addChild('ptoEmi', $invoice->emission_point);
        $taxInfo->addChild('secuencial', str_pad((string) $invoice->sequential, 9, '0', STR_PAD_LEFT));

        $info = $xml->addChild('infoFactura');
        $info->addChild('fechaEmision', $invoice->issue_date->format('d/m/Y'));
        $info->addChild('totalSinImpuestos', number_format((float) $invoice->subtotal, 2, '.', ''));
        $info->addChild('importeTotal', number_format((float) $invoice->total, 2, '.', ''));

        $items = $xml->addChild('detalles');
        foreach ($invoice->items as $item) {
            $detail = $items->addChild('detalle');
            $detail->addChild('codigoPrincipal', $item->code);
            $detail->addChild('descripcion', htmlspecialchars($item->name));
            $detail->addChild('cantidad', number_format((float) $item->quantity, 2, '.', ''));
            $detail->addChild('precioUnitario', number_format((float) $item->unit_price, 2, '.', ''));
            $detail->addChild('precioTotalSinImpuesto', number_format((float) $item->subtotal, 2, '.', ''));
        }

        return $xml->asXML() ?: '';
    }
}
