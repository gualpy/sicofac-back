<?php

namespace App\Http\Controllers\Api;

use App\DTOs\Billing\InvoiceItemData;
use App\Enums\TaxCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceAdditionalField;
use App\Models\InvoicePaymentMethod;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\InvoiceDraftService;
use App\Services\Billing\InvoiceEmissionService;
use App\Services\Billing\InvoiceTotalsCalculator;
use App\Services\Billing\RideGenerator;
use App\Enums\InvoiceStatus;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    )
    {
        $this->authorizeResource(Invoice::class, 'invoice');
    }

    public function index(Request $request, Company $company): JsonResponse
    {
        // authorizeResource() maps index -> InvoicePolicy::viewAny(), which is
        // an unscoped class-level check — see CustomerController::index().
        abort_unless($request->user()->belongsToCompany($company->id), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::in(array_column(InvoiceStatus::cases(), 'value'))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $company->invoices()->with(['customer', 'items']);

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('document_code', 'like', "%{$search}%")
                    ->orWhereRaw('CAST(sequential AS TEXT) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($validated['status'])) {
            $query->whereIn('status', $validated['status']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('issue_date', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('issue_date', '<=', $validated['to']);
        }

        return response()->json(
            $query->latest()->paginate((int) ($validated['per_page'] ?? 15))
        );
    }

    public function store(
        StoreInvoiceRequest $request,
        Company $company,
        InvoiceDraftService $draftService,
    ): JsonResponse {
        $validated = $request->validated();

        $invoice = $draftService->create(
            companyId: $company->id,
            customerId: $validated['customer_id'] ?? null,
            items: $this->toItemDtoArray($validated['items']),
            documentCode: $validated['document_code'] ?? '01',
            establishmentCode: $validated['establishment_code'] ?? $company->establishment_code,
            emissionPoint: $validated['emission_point'] ?? $company->emission_point,
            guideNumber: $validated['guide_number'] ?? null,
            isNegotiable: (bool) ($validated['is_negotiable'] ?? false),
            hasTip: (bool) ($validated['has_tip'] ?? false),
            paymentMethods: $validated['payment_methods'] ?? [],
            additionalFields: $validated['additional_fields'] ?? [],
        );
        $this->auditLogger->log('invoice.created', $invoice, null, $invoice->toArray());

        return response()->json($invoice, 201);
    }

    public function show(Company $company, Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load(['customer', 'items', 'document', 'events', 'paymentMethods', 'additionalFields']));
    }

    public function update(
        UpdateInvoiceRequest $request,
        Company $company,
        Invoice $invoice,
        InvoiceTotalsCalculator $totalsCalculator,
    ): JsonResponse {
        $before = $invoice->toArray();
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $invoice, $totalsCalculator) {
            if (array_key_exists('customer_id', $validated)) {
                $invoice->customer_id = $validated['customer_id'];
            }
            if (array_key_exists('guide_number', $validated)) {
                $invoice->guide_number = $validated['guide_number'];
            }
            if (array_key_exists('is_negotiable', $validated)) {
                $invoice->is_negotiable = $validated['is_negotiable'];
            }
            if (array_key_exists('has_tip', $validated)) {
                $invoice->has_tip = $validated['has_tip'];
            }
            $invoice->save();

            if (array_key_exists('items', $validated)) {
                $invoice->items()->delete();

                foreach ($this->toItemDtoArray($validated['items']) as $item) {
                    $invoice->items()->create([
                        'product_id' => $item->productId,
                        'code' => $item->code,
                        'name' => $item->name,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unitPrice,
                        'discount' => $item->discount,
                        'tax_rate' => $item->taxRate,
                        'tax_code' => $item->taxCode,
                        'tax_amount' => $item->taxAmount(),
                        'ice_rate' => $item->iceRate,
                        'ice_code' => $item->iceCode,
                        'ice_amount' => $item->iceAmount(),
                        'subtotal' => $item->subtotal(),
                        'total' => $item->total(),
                    ]);
                }
            }

            if (array_key_exists('payment_methods', $validated)) {
                $invoice->paymentMethods()->delete();

                foreach ($validated['payment_methods'] as $paymentMethod) {
                    InvoicePaymentMethod::query()->create([
                        'invoice_id' => $invoice->id,
                        'method' => $paymentMethod['method'],
                        'value' => $paymentMethod['value'],
                        'term_value' => $paymentMethod['term_value'] ?? null,
                        'term_unit' => $paymentMethod['term_unit'] ?? null,
                    ]);
                }
            }

            if (array_key_exists('additional_fields', $validated)) {
                $invoice->additionalFields()->delete();

                foreach ($validated['additional_fields'] as $additionalField) {
                    InvoiceAdditionalField::query()->create([
                        'invoice_id' => $invoice->id,
                        'name' => $additionalField['name'],
                        'description' => $additionalField['description'],
                    ]);
                }
            }

            $totals = $totalsCalculator->calculate($invoice->fresh('items'));
            $invoice->update([
                'subtotal' => $totals->subtotal,
                'discount' => $totals->discount,
                'subtotal_15' => $totals->subtotal15,
                'subtotal_5' => $totals->subtotal5,
                'subtotal_special' => $totals->subtotalSpecial,
                'subtotal_zero' => $totals->subtotalZero,
                'subtotal_not_subject' => $totals->subtotalNotSubject,
                'subtotal_exempt' => $totals->subtotalExempt,
                'tax' => $totals->tax,
                'tax_15' => $totals->tax15,
                'tax_5' => $totals->tax5,
                'tax_special' => $totals->taxSpecial,
                'ice_total' => $totals->iceTotal,
                'tip_amount' => $totals->tipAmount,
                'total' => $totals->total,
            ]);
        });

        $after = $invoice->fresh(['customer', 'items', 'paymentMethods', 'additionalFields']);
        $this->auditLogger->log('invoice.updated', $invoice, $before, $after->toArray());

        return response()->json($after);
    }

    public function destroy(Company $company, Invoice $invoice): JsonResponse
    {
        $before = $invoice->toArray();
        $invoice->delete();
        $this->auditLogger->log('invoice.deleted', $invoice, $before, null);

        return response()->json(status: 204);
    }

    public function emit(
        Company $company,
        Invoice $invoice,
        InvoiceEmissionService $emissionService,
    ): JsonResponse {
        $this->authorize('issue', $invoice);

        try {
            $pipeline = $emissionService->dispatch($invoice);
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'message' => 'Pipeline dispatched.',
            'invoice_id' => $invoice->id,
            'signing_enabled' => $pipeline->signingEnabled,
            'submission_enabled' => $pipeline->submissionEnabled,
        ]);
    }

    public function ride(Company $company, Invoice $invoice, RideGenerator $rideGenerator): Response
    {
        $this->authorize('view', $invoice);

        try {
            $pdf = $rideGenerator->generate($invoice);
        } catch (RuntimeException $exception) {
            return response($exception->getMessage(), 409);
        }

        $filename = "factura-{$invoice->establishment_code}-{$invoice->emission_point}-".str_pad((string) $invoice->sequential, 9, '0', STR_PAD_LEFT).'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, InvoiceItemData>
     */
    private function toItemDtoArray(array $items): array
    {
        return array_map(
            function (array $item) {
                $taxRate = (float) ($item['tax_rate'] ?? 0);
                $taxCode = isset($item['tax_code'])
                    ? TaxCode::from((string) $item['tax_code'])
                    : TaxCode::fromRate($taxRate);

                return new InvoiceItemData(
                    code: (string) $item['code'],
                    name: (string) $item['name'],
                    quantity: (float) $item['quantity'],
                    unitPrice: (float) $item['unit_price'],
                    productId: isset($item['product_id']) ? (int) $item['product_id'] : null,
                    discount: (float) ($item['discount'] ?? 0),
                    taxRate: $taxRate,
                    taxCode: $taxCode,
                    iceRate: (float) ($item['ice_rate'] ?? 0),
                    iceCode: $item['ice_code'] ?? null,
                );
            },
            $items
        );
    }
}
