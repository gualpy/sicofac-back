<?php

namespace App\Integrations\Sri\Real;

use App\Models\Invoice;

/**
 * Builds the 49-digit "clave de acceso" per SRI Ficha Tecnica de Comprobantes
 * Electronicos (Tabla 1), including the modulo-11 check digit (numeral 5.2).
 * In the offline authorization scheme the emisor generates this key; it is
 * never assigned by the SRI.
 */
class AccessKeyGenerator
{
    public function generate(Invoice $invoice): string
    {
        $invoice->loadMissing('company');
        $company = $invoice->company;

        $fechaEmision = $invoice->issue_date->format('dmY');
        $tipoComprobante = $invoice->document_code;
        $ruc = $company->ruc;
        $ambiente = $company->environment === 'production' ? '2' : '1';
        $serie = $invoice->establishment_code.$invoice->emission_point;
        $secuencial = str_pad((string) $invoice->sequential, 9, '0', STR_PAD_LEFT);
        $codigoNumerico = $this->numericCode($invoice);
        $tipoEmision = '1';

        $key48 = $fechaEmision.$tipoComprobante.$ruc.$ambiente.$serie.$secuencial.$codigoNumerico.$tipoEmision;

        return $key48.$this->modulo11CheckDigit($key48);
    }

    /**
     * The "codigo numerico" (8 digits) is left to the emisor's own criteria
     * (numeral 5.2). Derived deterministically from the invoice id so it
     * stays stable across job retries without needing extra storage.
     */
    private function numericCode(Invoice $invoice): string
    {
        $hash = crc32('sicofac-invoice-'.$invoice->id) % 100_000_000;

        return str_pad((string) $hash, 8, '0', STR_PAD_LEFT);
    }

    private function modulo11CheckDigit(string $digits): string
    {
        $sum = 0;
        $factor = 2;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $sum += ((int) $digits[$i]) * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $result = 11 - ($sum % 11);

        return match ($result) {
            11 => '0',
            10 => '1',
            default => (string) $result,
        };
    }
}
