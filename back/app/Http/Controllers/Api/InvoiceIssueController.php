<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Billing\IssueException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\IssueInvoiceRequest;
use App\Http\Resources\IssuedInvoiceResource;
use App\Models\Company;
use App\Services\Billing\InvoiceIssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceIssueController extends Controller
{
    public function issue(IssueInvoiceRequest $request, Company $company, InvoiceIssueService $service): JsonResponse
    {
        $key = (string) $request->header('Idempotency-Key');

        if (! preg_match('/^[A-Za-z0-9._:\-]{8,100}$/', $key)) {
            return $this->error('validation_failed', 'Idempotency-Key header is required (8-100 chars: letters, digits, . _ : -).', 422);
        }

        try {
            $result = $service->issue($company, $request->validated(), $key);
        } catch (IssueException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->details);
        }

        return (new IssuedInvoiceResource($result->invoice))
            ->response()
            ->setStatusCode($result->created ? 202 : 200);
    }

    public function showByReference(Request $request, Company $company, string $externalReference, InvoiceIssueService $service): JsonResponse
    {
        abort_unless($request->user()->belongsToCompany($company->id), 403);

        $invoice = $service->findByReference($company, $externalReference);

        if (! $invoice || $invoice->trashed()) {
            return $this->error('invoice_not_found', 'No invoice with this external_reference.', 404);
        }

        return (new IssuedInvoiceResource($invoice))->response();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => (object) $details]], $status);
    }
}
