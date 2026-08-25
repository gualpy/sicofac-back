<?php

namespace App\Services\Products;

use App\Models\Company;
use App\Models\ProductImport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProductImportQueryService
{
    public function paginateByCompany(Company $company, int $perPage = 15): LengthAwarePaginator
    {
        return ProductImport::query()
            ->where('company_id', $company->id)
            ->select(['id', 'company_id', 'user_id', 'status', 'valid_count', 'invalid_count', 'created_at'])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function detailForCompany(Company $company, ProductImport $import, int $errorLimit = 200): array
    {
        abort_unless($import->company_id === $company->id, 404);

        $errors = $import->errors_json ?? [];
        $totalErrors = count($errors);

        return [
            'id' => $import->id,
            'company_id' => $import->company_id,
            'user_id' => $import->user_id,
            'status' => $import->status,
            'valid_count' => $import->valid_count,
            'invalid_count' => $import->invalid_count,
            'errors_total' => $totalErrors,
            'errors_truncated' => $totalErrors > $errorLimit,
            'errors_json' => array_slice($errors, 0, $errorLimit),
            'created_at' => $import->created_at,
        ];
    }
}
