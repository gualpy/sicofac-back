<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\{StoreEmissionPointRequest, UpdateEmissionPointRequest};
use App\Models\{Company, CompanyEmissionPoint, CompanyEstablishment};
use Illuminate\Http\JsonResponse;

class CompanyEmissionPointController extends Controller
{
    public function store(
        StoreEmissionPointRequest $request,
        Company $company,
        CompanyEstablishment $establishment,
    ): JsonResponse {
        $emissionPoint = $establishment->emissionPoints()->create($request->validated());

        return response()->json($emissionPoint, 201);
    }

    public function update(
        UpdateEmissionPointRequest $request,
        Company $company,
        CompanyEstablishment $establishment,
        CompanyEmissionPoint $emissionPoint,
    ): JsonResponse {
        $emissionPoint->update($request->validated());

        return response()->json($emissionPoint->fresh());
    }
}
