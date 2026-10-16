<?php

namespace App\Services\Billing;

use App\DTOs\Billing\InvoiceItemData;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\IssueException;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Create + emit an invoice in one idempotent call (FitOS <-> SICOFAC contract).
 *
 * The same Idempotency-Key with the same payload always resolves to the same
 * invoice; a different payload under that key is rejected. Creation and
 * emission are separate transactions on purpose: the queue must only see
 * committed rows, and a crash in between leaves a numberless draft that the
 * next retry simply resumes (the fiscal number is taken at emission).
 */
class InvoiceIssueService
{
    public function __construct(
        private readonly InvoiceDraftService $drafts,
        private readonly InvoiceEmissionService $emission,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload  validated request body
     */
    public function issue(Company $company, array $payload, string $idempotencyKey): IssueResult
    {
        $hash = $this->hash($payload);

        if ($existing = $this->findByKey($company, $idempotencyKey)) {
            return $this->replay($existing, $hash);
        }

        if ($this->findByReference($company, $payload['external_reference'])) {
            throw new IssueException(
                'external_reference_conflict',
                'An invoice with this external_reference already exists under a different Idempotency-Key.',
                409,
                ['external_reference' => $payload['external_reference']],
            );
        }

        try {
            $invoice = $this->createDraft($company, $payload, $idempotencyKey, $hash);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key/reference committed first.
            if ($winner = $this->findByKey($company, $idempotencyKey)) {
                return $this->replay($winner, $hash);
            }

            throw new IssueException(
                'external_reference_conflict',
                'An invoice with this external_reference already exists under a different Idempotency-Key.',
                409,
                ['external_reference' => $payload['external_reference']],
            );
        }

        $this->emit($invoice);

        return new IssueResult($this->fresh($invoice), created: true);
    }

    public function findByReference(Company $company, string $externalReference): ?Invoice
    {
        return Invoice::query()
            ->withTrashed()
            ->where('company_id', $company->id)
            ->where('external_reference', $externalReference)
            ->first();
    }

    private function findByKey(Company $company, string $idempotencyKey): ?Invoice
    {
        return Invoice::query()
            ->withTrashed()
            ->where('company_id', $company->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function replay(Invoice $existing, string $hash): IssueResult
    {
        if (! hash_equals((string) $existing->request_hash, $hash)) {
            throw new IssueException(
                'idempotency_key_reused',
                'This Idempotency-Key was already used with a different request body.',
                422,
            );
        }

        if ($existing->trashed()) {
            throw new IssueException('invoice_deleted', 'The invoice created with this key was deleted.', 409);
        }

        if ($existing->status === InvoiceStatus::Draft) {
            $this->emit($existing); // previous attempt died between create and emit
        }

        return new IssueResult($this->fresh($existing), created: false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createDraft(Company $company, array $payload, string $idempotencyKey, string $hash): Invoice
    {
        return DB::transaction(function () use ($company, $payload, $idempotencyKey, $hash) {
            $invoice = $this->drafts->create(
                companyId: $company->id,
                customerId: $this->upsertBuyer($company, $payload['buyer'] ?? null)?->id,
                items: array_map(InvoiceItemData::fromArray(...), $payload['items']),
                documentCode: $payload['document_code'] ?? '01',
                establishmentCode: $payload['establishment_code'] ?? $company->establishment_code,
                emissionPoint: $payload['emission_point'] ?? $company->emission_point,
                guideNumber: $payload['guide_number'] ?? null,
                isNegotiable: (bool) ($payload['is_negotiable'] ?? false),
                hasTip: (bool) ($payload['has_tip'] ?? false),
                paymentMethods: $payload['payment_methods'] ?? [],
                additionalFields: $payload['additional_fields'] ?? [],
                externalReference: $payload['external_reference'],
                idempotencyKey: $idempotencyKey,
                requestHash: $hash,
            );

            if (isset($payload['expected_totals'])) {
                $this->assertTotalsMatch($invoice, $payload['expected_totals']); // throws -> rolls back
            }

            return $invoice;
        });
    }

    /**
     * Absent buyer = consumidor final (customer_id null; the XML builder
     * falls back to 07 / 9999999999999).
     *
     * @param  array<string, mixed>|null  $buyer
     */
    private function upsertBuyer(Company $company, ?array $buyer): ?Customer
    {
        if (! $buyer) {
            return null;
        }

        $customer = Customer::query()->firstOrNew([
            'company_id' => $company->id,
            'identification_number' => $buyer['identification_number'],
        ]);

        $customer->fill(array_filter(
            [
                'identification_type' => $buyer['identification_type'],
                'name' => $buyer['name'],
                'email' => $buyer['email'] ?? null,
                'phone' => $buyer['phone'] ?? null,
                'address' => $buyer['address'] ?? null,
            ],
            fn ($value) => $value !== null,
        ))->save();

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertTotalsMatch(Invoice $invoice, array $expected): void
    {
        $actual = [
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) $invoice->tax,
            'total' => (float) $invoice->total,
        ];

        $diff = [];
        foreach ($actual as $field => $value) {
            if (isset($expected[$field]) && abs((float) $expected[$field] - $value) >= 0.005) {
                $diff[$field] = ['expected' => (float) $expected[$field], 'calculated' => $value];
            }
        }

        if ($diff) {
            throw new IssueException(
                'totals_mismatch',
                'Totals calculated by SICOFAC differ from expected_totals; nothing was issued.',
                409,
                $diff,
            );
        }
    }

    private function emit(Invoice $invoice): void
    {
        try {
            $this->emission->dispatch($invoice);
        } catch (DomainException) {
            // Already past DRAFT: a concurrent retry emitted it first. Nothing to do.
        }
    }

    private function fresh(Invoice $invoice): Invoice
    {
        return $invoice->fresh(['items', 'paymentMethods']);
    }

    /**
     * Stable hash of the request body (object keys sorted, list order kept).
     *
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
