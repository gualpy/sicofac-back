<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'Helvetica', sans-serif; font-size: 9pt; color: #222; }
    table { border-collapse: collapse; width: 100%; }
    .header-table td { vertical-align: top; padding: 4px; }
    .box { border: 1px solid #999; padding: 8px; }
    .emisor h1 { font-size: 13pt; margin: 0 0 4px; }
    .emisor p { margin: 1px 0; }
    .factura-title { text-align: center; font-weight: bold; font-size: 11pt; margin-bottom: 4px; }
    .info-tributaria p { margin: 1px 0; }
    .ambiente { display: inline-block; padding: 2px 6px; border: 1px solid #333; font-weight: bold; }
    .comprador { margin-top: 10px; }
    .comprador table td { padding: 2px 4px; }
    .detalle { margin-top: 10px; }
    .detalle th, .detalle td { border: 1px solid #999; padding: 4px; font-size: 8pt; }
    .detalle th { background: #eee; text-align: left; }
    .totales { margin-top: 10px; }
    .totales table { width: 45%; float: right; }
    .totales td { padding: 2px 6px; }
    .totales .label { text-align: right; }
    .totales .total-final { font-weight: bold; border-top: 1px solid #333; }
    .clear { clear: both; }
    .adicional { margin-top: 60px; }
    .adicional table td { padding: 2px 4px; border: 1px solid #999; }
    .clave-acceso { text-align: center; margin-top: 10px; }
    .clave-acceso .numero { font-size: 8pt; letter-spacing: 1px; }
    .footer-note { text-align: center; font-size: 7pt; color: #666; margin-top: 6px; }
</style>
</head>
<body>

<table class="header-table">
    <tr>
        <td width="55%">
            <div class="emisor">
                @if($logoBase64)
                    <img src="{{ $logoBase64 }}" style="max-width: 140px; max-height: 70px;"><br>
                @endif
                <h1>{{ $company->name }}</h1>
                @if($company->trade_name)
                    <p>{{ $company->trade_name }}</p>
                @endif
                <p>{{ $company->address }}</p>
                @if($company->phone)
                    <p>Telf: {{ $company->phone }}</p>
                @endif
                @if($company->email)
                    <p>Email: {{ $company->email }}</p>
                @endif
                @if($company->requires_accounting)
                    <p>Obligado a llevar contabilidad: SI</p>
                @endif
            </div>
        </td>
        <td width="45%">
            <div class="box info-tributaria">
                <div class="factura-title">FACTURA</div>
                <p>R.U.C.: <strong>{{ $company->ruc }}</strong></p>
                <p>No. {{ $invoice->establishment_code }}-{{ $invoice->emission_point }}-{{ str_pad((string) $invoice->sequential, 9, '0', STR_PAD_LEFT) }}</p>
                <p>NUMERO DE AUTORIZACION:</p>
                <p style="word-break: break-all;">{{ $invoice->sri_authorization_number ?? $invoice->access_key }}</p>
                <p>Fecha y hora de autorizacion:<br>{{ optional($invoice->authorized_at)->format('d/m/Y H:i:s') }}</p>
                <p>Ambiente: <span class="ambiente">{{ $company->environment === 'production' ? 'PRODUCCION' : 'PRUEBAS' }}</span></p>
                <p>Emision: NORMAL</p>
                <p>Clave de acceso:</p>
            </div>
        </td>
    </tr>
    
</table>

<div class="clave-acceso">
    <table style="margin: 0 auto; width: auto;">
        <tr><td>{!! $barcodeHtml !!}</td></tr>
        <tr><td class="numero" style="text-align: center;">{{ $invoice->access_key }}</td></tr>
    </table>
</div>

<div class="comprador box">
    <table>
        <tr>
            <td width="60%"><strong>Razon social / Nombres y apellidos:</strong> {{ $customerName }}</td>
            <td width="40%"><strong>Fecha de emision:</strong> {{ $invoice->issue_date->format('d/m/Y') }}</td>
        </tr>
        <tr>
            <td><strong>Identificacion:</strong> {{ $customerIdentification }}</td>
            <td><strong>Guia de remision:</strong> {{ $invoice->guide_number ?? '-' }}</td>
        </tr>
        @if($customerAddress)
        <tr>
            <td colspan="2"><strong>Direccion:</strong> {{ $customerAddress }}</td>
        </tr>
        @endif
        @if($customerPhone || $customerEmail)
        <tr>
            @if($customerPhone)
            <td><strong>Telefono:</strong> {{ $customerPhone }}</td>
            @endif
            @if($customerEmail)
            <td @if(!$customerPhone) colspan="2" @endif><strong>Email:</strong> {{ $customerEmail }}</td>
            @endif
        </tr>
        @endif
    </table>
</div>

<div class="detalle">
    <table>
        <thead>
            <tr>
                <th>Cod. Principal</th>
                <th>Cantidad</th>
                <th>Descripcion</th>
                <th>Precio Unitario</th>
                <th>Descuento</th>
                <th>Precio Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
            <tr>
                <td>{{ $item->code }}</td>
                <td>{{ number_format((float) $item->quantity, 2) }}</td>
                <td>{{ $item->name }}</td>
                <td>{{ number_format((float) $item->unit_price, 2) }}</td>
                <td>{{ number_format((float) $item->discount, 2) }}</td>
                <td>{{ number_format((float) $item->subtotal, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="totales">
    <table>
        @if((float) $invoice->subtotal_15 > 0)
        <tr><td class="label">Subtotal 15%:</td><td>{{ number_format((float) $invoice->subtotal_15, 2) }}</td></tr>
        @endif
        @if((float) $invoice->subtotal_5 > 0)
        <tr><td class="label">Subtotal 5%:</td><td>{{ number_format((float) $invoice->subtotal_5, 2) }}</td></tr>
        @endif
        @if((float) $invoice->subtotal_zero > 0)
        <tr><td class="label">Subtotal 0%:</td><td>{{ number_format((float) $invoice->subtotal_zero, 2) }}</td></tr>
        @endif
        @if((float) $invoice->subtotal_not_subject > 0)
        <tr><td class="label">Subtotal no objeto de IVA:</td><td>{{ number_format((float) $invoice->subtotal_not_subject, 2) }}</td></tr>
        @endif
        @if((float) $invoice->subtotal_exempt > 0)
        <tr><td class="label">Subtotal exento de IVA:</td><td>{{ number_format((float) $invoice->subtotal_exempt, 2) }}</td></tr>
        @endif
        <tr><td class="label">Subtotal sin impuestos:</td><td>{{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
        <tr><td class="label">Descuento:</td><td>{{ number_format((float) $invoice->discount, 2) }}</td></tr>
        @if((float) $invoice->ice_total > 0)
        <tr><td class="label">ICE:</td><td>{{ number_format((float) $invoice->ice_total, 2) }}</td></tr>
        @endif
        @if((float) $invoice->tax_15 > 0)
        <tr><td class="label">IVA 15%:</td><td>{{ number_format((float) $invoice->tax_15, 2) }}</td></tr>
        @endif
        @if((float) $invoice->tax_5 > 0)
        <tr><td class="label">IVA 5%:</td><td>{{ number_format((float) $invoice->tax_5, 2) }}</td></tr>
        @endif
        @if((float) $invoice->tax_special > 0)
        <tr><td class="label">IVA:</td><td>{{ number_format((float) $invoice->tax_special, 2) }}</td></tr>
        @endif
        @if((float) $invoice->tax_15 <= 0 && (float) $invoice->tax_5 <= 0 && (float) $invoice->tax_special <= 0)
        <tr><td class="label">IVA:</td><td>{{ number_format((float) $invoice->tax, 2) }}</td></tr>
        @endif
        @if($invoice->has_tip)
        <tr><td class="label">Propina:</td><td>{{ number_format((float) $invoice->tip_amount, 2) }}</td></tr>
        @endif
        <tr class="total-final"><td class="label">VALOR TOTAL:</td><td>{{ number_format((float) $invoice->total, 2) }}</td></tr>
    </table>
</div>

<div class="clear"></div>

<div class="adicional">
    <table>
        <tr>
            <td width="40%"><strong>Forma de pago</strong></td>
            <td width="30%"><strong>Plazo</strong></td>
            <td width="30%"><strong>Valor</strong></td>
        </tr>
        @forelse($invoice->paymentMethods as $method)
        <tr>
            <td>{{ $paymentMethodLabels[$method->method->value] ?? $method->method->value }}</td>
            <td>
                @if($method->term_value)
                    {{ $method->term_value }} {{ $paymentTermUnitLabels[$method->term_unit?->value] ?? $method->term_unit?->value }}
                @else
                    -
                @endif
            </td>
            <td>{{ number_format((float) $method->value, 2) }}</td>
        </tr>
        @empty
        <tr><td colspan="3">-</td></tr>
        @endforelse
    </table>

    @if($invoice->additionalFields->isNotEmpty())
    <table style="margin-top: 6px;">
        @foreach($invoice->additionalFields as $field)
        <tr>
            <td width="30%"><strong>{{ $field->name }}</strong></td>
            <td>{{ $field->description }}</td>
        </tr>
        @endforeach
    </table>
    @endif
</div>

<div class="footer-note">
    Este documento es una representacion impresa de un comprobante electronico (RIDE), autorizado por el SRI.
</div>

</body>
</html>
