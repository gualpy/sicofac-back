<?php

namespace App\Services\Products;

use App\Jobs\Products\ImportProductsJob;
use App\Models\Company;
use App\Models\ProductImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImportService
{
    public function startImport(Company $company, UploadedFile $file, ?int $userId): ProductImport
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $filename = Str::uuid()->toString().'.'.$extension;
        $path = "imports/products/{$company->id}/{$filename}";

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $import = ProductImport::query()->create([
            'company_id' => $company->id,
            'user_id' => $userId,
            'status' => 'processing',
            'valid_count' => 0,
            'invalid_count' => 0,
            'errors_json' => [],
        ]);

        ImportProductsJob::dispatch(
            productImportId: $import->id,
            filePath: $path,
        );

        return $import;
    }
}
