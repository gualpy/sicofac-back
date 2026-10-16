<?php

namespace App\Http\Resources;

use App\Enums\InvoiceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable API v1 shape of an issued invoice. Internal pipeline states are
 * collapsed so API clients do not depend on them.
 *
 * @mixin \App\Models\Invoice
 */
class IssuedInvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = match ($this->status) {
            InvoiceStatus::Draft => 'draft',
            InvoiceStatus::Authorized => 'authorized',
            InvoiceStatus::Rejected => 'rejected',
            InvoiceStatus::Failed => 'failed',
            default => 'processing',
        };

        return [
            'id' => $this->id,
            'external_reference' => $this->external_reference,
            'status' => $status,
            'document_code' => $this->document_code,
            'establishment_code' => $this->establishment_code,
            'emission_point' => $this->emission_point,
            'sequential' => $this->sequential,
            'access_key' => $this->access_key,
            'authorization_number' => $this->sri_authorization_number,
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'totals' => [
                'subtotal' => (float) $this->subtotal,
                'tax' => (float) $this->tax,
                'total' => (float) $this->total,
            ],
            'ride_url' => route('companies.invoices.ride', [$this->company_id, $this->id], false),
            'error' => in_array($status, ['rejected', 'failed'], true)
                ? ['code' => $status, 'details' => $this->sri_response_payload]
                : null,
        ];
    }
}
