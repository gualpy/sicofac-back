<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CompanyMembershipRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CompanyController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $query = Company::query()
            ->with(['plan:id,name,slug,price_cents', 'users' => fn ($q) => $q->wherePivot('role', CompanyMembershipRole::Owner->value)]);

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")->orWhere('ruc', 'like', "%{$search}%");
            });
        }

        $companies = $query->orderByDesc('id')->paginate(20);

        $monthStart = Carbon::now()->startOfMonth()->toDateString();
        $monthEnd = Carbon::now()->endOfMonth()->toDateString();

        $companies->getCollection()->transform(function (Company $company) use ($monthStart, $monthEnd) {
            $owner = $company->users->first();

            return [
                'id' => $company->id,
                'name' => $company->name,
                'ruc' => $company->ruc,
                'is_active' => $company->is_active,
                'created_at' => $company->created_at,
                'plan' => $company->plan,
                'owner_email' => $owner?->email,
                'owner_name' => $owner?->name,
                'documents_this_month' => Invoice::query()
                    ->where('company_id', $company->id)
                    ->whereBetween('issue_date', [$monthStart, $monthEnd])
                    ->count(),
            ];
        });

        return response()->json($companies);
    }

    public function activate(Company $company): JsonResponse
    {
        $company->update(['is_active' => true]);
        $this->auditLogger->log('admin.company.activated', $company, null, null, companyId: $company->id);

        return response()->json(['id' => $company->id, 'is_active' => true]);
    }

    public function deactivate(Company $company): JsonResponse
    {
        $company->update(['is_active' => false]);
        $this->auditLogger->log('admin.company.deactivated', $company, null, null, companyId: $company->id);

        return response()->json(['id' => $company->id, 'is_active' => false]);
    }
}
