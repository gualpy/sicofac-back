<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlanRequest;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Company;
use App\Models\Plan;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

class PlanController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(): JsonResponse
    {
        // Unlike the public PlanController, this lists every plan including
        // non-public ones (e.g. Empresa) -- admins manage the full catalog.
        return response()->json(['plans' => Plan::query()->orderBy('sort_order')->get()]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $plan = Plan::query()->create($request->validated());
        $this->auditLogger->log('admin.plan.created', $plan, null, $plan->toArray());

        return response()->json($plan, 201);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $before = $plan->toArray();
        $plan->update($request->validated());
        $this->auditLogger->log('admin.plan.updated', $plan, $before, $plan->fresh()->toArray());

        return response()->json($plan->fresh());
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if (Company::query()->where('plan_id', $plan->id)->exists()) {
            return response()->json([
                'message' => 'No se puede borrar: hay empresas en este plan. Cambialas de plan primero.',
            ], 409);
        }

        $before = $plan->toArray();
        $plan->delete();
        $this->auditLogger->log('admin.plan.deleted', null, $before, null, meta: ['plan_id' => $before['id']]);

        return response()->json(status: 204);
    }
}
