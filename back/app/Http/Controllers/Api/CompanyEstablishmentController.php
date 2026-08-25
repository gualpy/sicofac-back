<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

class CompanyEstablishmentController extends Controller
{
    public function index(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return response()->json(
            $company->establishments()
                ->with(['emissionPoints' => fn ($query) => $query->where('is_active', true)])
                ->where('is_active', true)
                ->get()
        );
    }
}
