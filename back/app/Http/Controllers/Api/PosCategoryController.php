<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PosCategory\StorePosCategoryRequest;
use App\Http\Requests\PosCategory\UpdatePosCategoryRequest;
use App\Models\Company;
use App\Models\PosCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosCategoryController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(PosCategory::class, 'posCategory');
    }

    public function index(Request $request, Company $company): JsonResponse
    {
        // authorizeResource() only calls PosCategoryPolicy::viewAny() for index,
        // which is an unscoped class-level check — see CustomerController::index().
        abort_unless($request->user()->belongsToCompany($company->id), 403);

        return response()->json(
            $company->posCategories()->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function store(StorePosCategoryRequest $request, Company $company): JsonResponse
    {
        $posCategory = $company->posCategories()->create($request->validated());

        return response()->json($posCategory, 201);
    }

    public function show(Company $company, PosCategory $posCategory): JsonResponse
    {
        return response()->json($posCategory);
    }

    public function update(UpdatePosCategoryRequest $request, Company $company, PosCategory $posCategory): JsonResponse
    {
        $posCategory->update($request->validated());

        return response()->json($posCategory->fresh());
    }

    public function destroy(Company $company, PosCategory $posCategory): JsonResponse
    {
        $posCategory->delete();

        return response()->json(status: 204);
    }
}
