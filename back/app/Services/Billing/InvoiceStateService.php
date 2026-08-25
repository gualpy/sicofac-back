<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;

class InvoiceStateService
{
    public function setStatus(Invoice $invoice, InvoiceStatus $status, array $payload = []): void
    {
        $invoice->status = $status;

        if ($status === InvoiceStatus::XmlBuilt) {
            $invoice->xml_generated_at = now();
        }

        if ($status === InvoiceStatus::Signed) {
            $invoice->signed_at = now();
        }

        if ($status === InvoiceStatus::SentReception) {
            $invoice->sent_at = now();
        }

        if ($status === InvoiceStatus::Authorized) {
            $invoice->authorized_at = now();
        }

        $invoice->save();

        $invoice->events()->create([
            'event' => $status->value,
            'payload' => $payload,
        ]);
    }
}
