<?php

namespace App\Http\Controllers\Api;

use App\Enums\CompanyMembershipRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Product\{ExportProductsRequest, ImportProductsRequest, ListProductImportsRequest, ShowProductImportRequest, StoreProductRequest, UpdateProductRequest};
use App\Models\{Company, Product, ProductImport};
use App\Services\Audit\AuditLogger;
use App\Services\Products\ProductExportService;
use App\Services\Products\ProductImportService;
use App\Services\Products\ProductImportQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    )
    {
        $this->authorizeResource(Product::class, 'product');
    }

    public function index(Request $request, Company $company): JsonResponse
    {
        // See CustomerController::index() for why this explicit check is needed:
        // authorizeResource() only calls ProductPolicy::viewAny() (unscoped) here.
        abort_unless($request->user()->belongsToCompany($company->id), 403);

        $query = $company->products()->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($code = trim((string) $request->query('code', ''))) {
            $query->where('code', 'like', "%{$code}%");
        }

        if ($name = trim((string) $request->query('name', ''))) {
            $query->where('name', 'like', "%{$name}%");
        }

        if ($request->has('pos_enabled')) {
            $query->where('pos_enabled', $request->boolean('pos_enabled'));
        }

        if ($posCategoryId = $request->query('pos_category_id')) {
            $query->where('pos_category_id', $posCategoryId);
        }

        if ($barcode = trim((string) $request->query('barcode', ''))) {
            $query->where('barcode', $barcode);
        }

        return response()->json($query->paginate((int) $request->query('per_page', 15)));
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

    public function destroyAll(Request $request, Company $company): JsonResponse
    {
        abort_unless(
            $request->user()->hasCompanyRole($company->id, [
                CompanyMembershipRole::Owner->value,
                CompanyMembershipRole::Admin->value,
            ]),
            403,
        );

        $products = $company->products()->get();
        $deleted = 0;
        $skipped = 0;

        foreach ($products as $product) {
            if ($product->invoiceItems()->exists()) {
                $skipped++;
                continue;
            }

            // Hard delete here: "eliminar todos" is meant as a full catalog
            // reset, and a soft-deleted row would keep occupying the
            // (company_id, code) unique slot forever, blocking reuse.
            $product->forceDelete();
            $deleted++;
        }

        $this->auditLogger->log('product.deleted_all', null, null, [
            'deleted' => $deleted,
            'skipped' => $skipped,
        ], companyId: $company->id);

        return response()->json(['deleted' => $deleted, 'skipped' => $skipped]);
    }

    public function import(
        ImportProductsRequest $request,
        Company $company,
        ProductImportService $productImportService,
    ): JsonResponse {
        $import = $productImportService->startImport(
            company: $company,
            file: $request->file('file'),
            userId: $request->user()?->id,
        );

        $this->auditLogger->log('product.imported', null, null, [
            'company_id' => $company->id,
            'import_id' => $import->id,
            'status' => $import->status,
        ], [
            'filename' => $request->file('file')->getClientOriginalName(),
        ], $company->id);

        return response()->json([
            'status' => 'processing',
            'import_id' => $import->id,
        ], 202);
    }

    public function export(
        ExportProductsRequest $request,
        Company $company,
        ProductExportService $productExportService,
    ) {
        $this->auditLogger->log('product.exported', null, null, null, [
            'company_id' => $company->id,
        ], $company->id);

        return $productExportService->downloadCsv($company);
    }

    public function importTemplate(
        ExportProductsRequest $request,
        Company $company,
    ): StreamedResponse {
        $format = strtolower((string) $request->query('format', 'csv'));
        abort_if($format !== 'csv', 422, 'Only csv format is supported.');

        $this->auditLogger->log('product.import_template.downloaded', null, null, null, [
            'company_id' => $company->id,
        ], $company->id);

        return response()->streamDownload(
            static function (): void {
                $handle = fopen('php://output', 'wb');

                if ($handle === false) {
                    return;
                }

                fputcsv($handle, ['sku', 'name', 'price', 'tax_rate', 'is_active']);
                fputcsv($handle, ['SKU-001', 'Producto Demo', '10.50', '15', '1']);
                fclose($handle);
            },
            'products_import_template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function imports(
        ListProductImportsRequest $request,
        Company $company,
        ProductImportQueryService $queryService,
    ): JsonResponse {
        $perPage = (int) $request->integer('per_page', 15);

        return response()->json(
            $queryService->paginateByCompany($company, $perPage)
        );
    }

    public function showImport(
        ShowProductImportRequest $request,
        Company $company,
        ProductImport $import,
        ProductImportQueryService $queryService,
    ): JsonResponse {
        $errorLimit = (int) $request->integer('error_limit', 200);

        return response()->json(
            $queryService->detailForCompany($company, $import, $errorLimit)
        );
    }
}
