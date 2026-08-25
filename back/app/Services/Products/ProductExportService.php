<?php

namespace App\Services\Products;

use App\Exports\CompanyProductsExport;
use App\Models\Company;
use Maatwebsite\Excel\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductExportService
{
    public function downloadCsv(Company $company): BinaryFileResponse
    {
        $filename = "products_company_{$company->id}.csv";

        return \Maatwebsite\Excel\Facades\Excel::download(
            new CompanyProductsExport($company->id),
            $filename,
            Excel::CSV,
        );
    }
}
