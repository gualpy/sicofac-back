<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\{StoreProductRequest, UpdateProductRequest};
use App\Models\{Company, Product};
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    )
    {
        $this->authorizeResource(Product::class, 'product');
    }

    public function index(Company $company): JsonResponse
    {
        return response()->json(
            $company->products()->latest()->paginate(15)
        );
    }

    public function store(StoreProductRequest $request, Company $company): JsonResponse
    {
        $product = $company->products()->create($request->validated());
        $this->auditLogger->log('product.created', $product, null, $product->toArray());

        return response()->json($product, 201);
    }

    public function show(Company $company, Product $product): JsonResponse
    {
        return response()->json($product);
    }

    public function update(UpdateProductRequest $request, Company $company, Product $product): JsonResponse
    {
        $before = $product->toArray();
        $product->update($request->validated());
        $after = $product->fresh()->toArray();
        $this->auditLogger->log('product.updated', $product, $before, $after);

        return response()->json($product->fresh());
    }

    public function destroy(Company $company, Product $product): JsonResponse
    {
        $before = $product->toArray();
        $product->delete();
        $this->auditLogger->log('product.deleted', $product, $before, null);

        return response()->json(status: 204);
    }
}
