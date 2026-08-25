<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CompanyProductsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(private readonly int $companyId)
    {
    }

    public function query(): Builder
    {
        return Product::query()
            ->where('company_id', $this->companyId)
            ->orderBy('id');
    }

    /**
     * @param  Product  $product
     * @return array<int, scalar|null>
     */
    public function map($product): array
    {
        return [
            $product->code,
            $product->name,
            (string) $product->unit_price,
            (string) $product->tax_rate,
            $product->is_active ? '1' : '0',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function headings(): array
    {
        return ['sku', 'name', 'price', 'tax_rate', 'is_active'];
    }
}
