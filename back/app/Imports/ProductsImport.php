<?php

namespace App\Imports;

use App\DTOs\Products\ProductImportResultData;
use App\Models\Product;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class ProductsImport implements OnEachRow, WithHeadingRow, WithChunkReading
{
    private int $validCount = 0;

    private int $invalidCount = 0;

    /** @var array<int, array{row:int,messages:array<int,string>}> */
    private array $errors = [];

    public function __construct(
        private readonly int $companyId,
    ) {
    }

    public function onRow(Row $row): void
    {
        $payload = $row->toArray();

        $normalized = [
            'sku' => trim((string) ($payload['sku'] ?? $payload['product_code'] ?? '')),
            'name' => trim((string) ($payload['name'] ?? '')),
            'price' => $payload['price'] ?? $payload['unit_price'] ?? null,
            'tax_rate' => $payload['tax_rate'] ?? 0,
            'is_active' => $payload['is_active'] ?? true,
        ];

        $validator = Validator::make($normalized, [
            'sku' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            $this->invalidCount++;
            $this->errors[] = [
                'row' => $row->getIndex(),
                'messages' => $validator->errors()->all(),
            ];

            return;
        }

        $data = $validator->validated();

        $product = Product::query()
            ->where('company_id', $this->companyId)
            ->where('code', $data['sku'])
            ->first();

        if (! $product) {
            Product::query()->create([
                'company_id' => $this->companyId,
                'code' => $data['sku'],
                'name' => $data['name'],
                'unit_price' => $data['price'],
                'tax_rate' => $data['tax_rate'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);
            $this->validCount++;

            return;
        }

        $product->update([
            'name' => $data['name'],
            'unit_price' => $data['price'],
            'tax_rate' => $data['tax_rate'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        $this->validCount++;
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function result(): ProductImportResultData
    {
        return new ProductImportResultData(
            validCount: $this->validCount,
            invalidCount: $this->invalidCount,
            errors: $this->errors,
        );
    }
}
