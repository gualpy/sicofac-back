<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {
        $this->authorizeResource(Customer::class, 'customer');
    }

    public function index(Request $request, Company $company): JsonResponse
    {
        // authorizeResource() maps index -> CustomerPolicy::viewAny(), which is
        // an unscoped class-level check (any authenticated user passes it) since
        // there is no Customer instance yet to re-derive company_id from. This
        // guard is what actually keeps a member of another company from listing
        // this company's customers by changing the URL's {company} segment.
        abort_unless($request->user()->belongsToCompany($company->id), 403);

        $query = $company->customers()->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            // The customers list displays "{tipo} {numero}" (e.g. "05 1712345678");
            // strip a pasted type prefix so that exact copy/paste still matches.
            $identificationSearch = preg_replace('/^\d{2}\s+/', '', $search);

            $query->where(function ($inner) use ($search, $identificationSearch) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('identification_number', 'like', "%{$search}%");

                if ($identificationSearch !== $search) {
                    $inner->orWhere('identification_number', 'like', "%{$identificationSearch}%");
                }
            });
        }

        return response()->json($query->paginate((int) $request->query('per_page', 15)));
    }

    public function store(StoreCustomerRequest $request, Company $company): JsonResponse
    {
        $customer = $company->customers()->create($request->validated());
        $this->auditLogger->log('customer.created', $customer, null, $customer->toArray());

        return response()->json($customer, 201);
    }

    public function show(Company $company, Customer $customer): JsonResponse
    {
        return response()->json($customer);
    }

    public function update(UpdateCustomerRequest $request, Company $company, Customer $customer): JsonResponse
    {
        $before = $customer->toArray();
        $customer->update($request->validated());
        $after = $customer->fresh()->toArray();
        $this->auditLogger->log('customer.updated', $customer, $before, $after);

        return response()->json($customer->fresh());
    }

    public function destroy(Company $company, Customer $customer): JsonResponse
    {
        $before = $customer->toArray();
        $customer->delete();
        $this->auditLogger->log('customer.deleted', $customer, $before, null);

        return response()->json(status: 204);
    }
}
