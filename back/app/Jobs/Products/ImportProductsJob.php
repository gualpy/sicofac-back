<?php

namespace App\Jobs\Products;

use App\Imports\ProductsImport;
use App\Models\ProductImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportProductsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function __construct(
        private readonly int $productImportId,
        private readonly string $filePath,
    ) {
    }

    public function handle(): void
    {
        $import = ProductImport::query()->findOrFail($this->productImportId);

        try {
            $rowsImport = new ProductsImport($import->company_id);

            Excel::import($rowsImport, $this->filePath, 'local');

            $result = $rowsImport->result();

            $import->update([
                'status' => 'completed',
                'valid_count' => $result->validCount,
                'invalid_count' => $result->invalidCount,
                'errors_json' => $result->errors,
            ]);
        } catch (\Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'errors_json' => [[
                    'row' => 0,
                    'messages' => [$exception->getMessage()],
                ]],
            ]);

            throw $exception;
        } finally {
            Storage::disk('local')->delete($this->filePath);
        }
    }
}
