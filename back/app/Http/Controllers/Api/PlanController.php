<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;

/**
 * Public (unauthenticated) plan listing for the registration picker.
 */
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'name', 'price_cents', 'monthly_document_limit', 'max_establishments', 'max_users']);

        return response()->json(['plans' => $plans]);
    }
}
