<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    public function index(): JsonResponse
    {
        // Plans are priced per year (see plans.price_cents); MRR is an
        // estimate (annual price / 12), not money actually collected --
        // SICOFAC has no payment processor wired up yet.
        $mrrCents = (int) Company::query()
            ->where('companies.is_active', true)
            ->whereNotNull('companies.plan_id')
            ->join('plans', 'companies.plan_id', '=', 'plans.id')
            ->sum(DB::raw('plans.price_cents / 12.0'));

        $byPlan = Company::query()
            ->select('plans.name as plan_name', DB::raw('count(*) as companies_count'))
            ->join('plans', 'companies.plan_id', '=', 'plans.id')
            ->where('companies.is_active', true)
            ->groupBy('plans.name')
            ->get();

        return response()->json([
            'mrr_cents' => $mrrCents,
            'active_companies' => Company::query()->where('is_active', true)->count(),
            'inactive_companies' => Company::query()->where('is_active', false)->count(),
            'companies_by_plan' => $byPlan,
        ]);
    }
}
