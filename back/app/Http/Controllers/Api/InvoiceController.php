<?php

namespace App\Http\Controllers\Api;

use App\DTOs\Billing\InvoiceItemData;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\InvoiceDraftService;
use App\Services\Billing\InvoiceEmissionService;
use App\Services\Billing\InvoiceTotalsCalculator;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    )
    {
        $this->authorizeResource(Invoice::class, 'invoice');
    }

    public function index(Company $company): JsonResponse
    {
        return response()->json(
            $company->invoices()
                ->with(['customer', 'items'])
                ->latest()
                ->paginate(15)
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
            establishmentCode: $company->establishment_code,
            emissionPoint: $company->emission_point,
        );
        $this->auditLogger->log('invoice.created', $invoice, null, $invoice->toArray());

        return response()->json($invoice, 201);
    }

    public function show(Company $company, Invoice $invoice): JsonResponse
    {
        return response()->json($invoice->load(['customer', 'items', 'document', 'events']));
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
                $invoice->save();
            }

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
                        'tax_amount' => $item->taxAmount(),
                        'subtotal' => $item->subtotal(),
                        'total' => $item->total(),
                    ]);
                }
            }

            $totals = $totalsCalculator->calculate($invoice->fresh('items'));
            $invoice->update([
                'subtotal' => $totals->subtotal,
                'discount' => $totals->discount,
                'tax' => $totals->tax,
                'total' => $totals->total,
            ]);
        });

        $after = $invoice->fresh(['customer', 'items']);
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

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, InvoiceItemData>
     */
    private function toItemDtoArray(array $items): array
    {
        return array_map(
            fn (array $item) => new InvoiceItemData(
                code: (string) $item['code'],
                name: (string) $item['name'],
                quantity: (float) $item['quantity'],
                unitPrice: (float) $item['unit_price'],
                productId: isset($item['product_id']) ? (int) $item['product_id'] : null,
                discount: (float) ($item['discount'] ?? 0),
                taxRate: (float) ($item['tax_rate'] ?? 0),
            ),
            $items
        );
    }
}
