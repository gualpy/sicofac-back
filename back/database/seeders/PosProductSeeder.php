<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PosProductSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed 4 POS categories and 10 POS-enabled demo products (heladeria)
     * for every existing company.
     */
    public function run(): void
    {
        $categories = [
            'Helados' => 1,
            'Bebidas' => 2,
            'Postres' => 3,
            'Extras' => 4,
        ];

        $products = [
            ['code' => 'POS001', 'name' => 'Cono simple', 'unit_price' => 2.00, 'category' => 'Helados', 'sort' => 1, 'barcode' => '750000000001'],
            ['code' => 'POS002', 'name' => 'Cono doble', 'unit_price' => 3.50, 'category' => 'Helados', 'sort' => 2, 'barcode' => '750000000002'],
            ['code' => 'POS003', 'name' => 'Copa grande', 'unit_price' => 4.50, 'category' => 'Helados', 'sort' => 3, 'barcode' => '750000000003'],
            ['code' => 'POS004', 'name' => 'Sundae', 'unit_price' => 3.75, 'category' => 'Helados', 'sort' => 4, 'barcode' => '750000000004'],
            ['code' => 'POS005', 'name' => 'Batido', 'unit_price' => 4.00, 'category' => 'Bebidas', 'sort' => 1, 'barcode' => '750000000005'],
            ['code' => 'POS006', 'name' => 'Agua', 'unit_price' => 1.00, 'category' => 'Bebidas', 'sort' => 2, 'barcode' => '750000000006'],
            ['code' => 'POS007', 'name' => 'Cafe', 'unit_price' => 1.50, 'category' => 'Bebidas', 'sort' => 3, 'barcode' => '750000000007'],
            ['code' => 'POS008', 'name' => 'Brownie', 'unit_price' => 3.00, 'category' => 'Postres', 'sort' => 1, 'barcode' => '750000000008'],
            ['code' => 'POS009', 'name' => 'Postre Oreo', 'unit_price' => 2.50, 'category' => 'Postres', 'sort' => 2, 'barcode' => '750000000009'],
            ['code' => 'POS010', 'name' => 'Extra Oreo', 'unit_price' => 0.50, 'category' => 'Extras', 'sort' => 1, 'barcode' => '750000000010'],
        ];

        Company::all()->each(function (Company $company) use ($categories, $products) {
            $categoryIds = [];
            foreach ($categories as $name => $sortOrder) {
                $category = $company->posCategories()->updateOrCreate(
                    ['name' => $name],
                    ['sort_order' => $sortOrder, 'is_active' => true],
                );
                $categoryIds[$name] = $category->id;
            }

            foreach ($products as $product) {
                $company->products()->updateOrCreate(
                    ['code' => $product['code']],
                    [
                        'name' => $product['name'],
                        'unit_price' => $product['unit_price'],
                        'tax_rate' => 15,
                        'tax_code' => '15',
                        'is_active' => true,
                        'pos_enabled' => true,
                        'pos_category_id' => $categoryIds[$product['category']],
                        'pos_sort_order' => $product['sort'],
                        'barcode' => $product['barcode'],
                    ]
                );
            }
        });
    }
}
