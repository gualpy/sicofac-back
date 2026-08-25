<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ProductCustomerSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed 5 demo products and 5 demo customers for every existing company.
     */
    public function run(): void
    {
        $products = [
            ['code' => 'P001', 'name' => 'Laptop 14 pulgadas', 'unit_price' => 750.00, 'tax_rate' => 15],
            ['code' => 'P002', 'name' => 'Mouse inalambrico', 'unit_price' => 15.50, 'tax_rate' => 15],
            ['code' => 'P003', 'name' => 'Teclado mecanico', 'unit_price' => 45.00, 'tax_rate' => 15],
            ['code' => 'P004', 'name' => 'Monitor 24 pulgadas', 'unit_price' => 180.00, 'tax_rate' => 15],
            ['code' => 'P005', 'name' => 'Servicio de soporte tecnico', 'unit_price' => 25.00, 'tax_rate' => 0],
        ];

        $customers = [
            ['identification_type' => '05', 'identification_number' => '1712345678', 'name' => 'Juan Perez', 'email' => 'juan.perez@example.com'],
            ['identification_type' => '05', 'identification_number' => '1798765432', 'name' => 'Maria Gonzalez', 'email' => 'maria.gonzalez@example.com'],
            ['identification_type' => '04', 'identification_number' => '1790123456001', 'name' => 'Comercial Andina S.A.', 'email' => 'ventas@comercialandina.example.com'],
            ['identification_type' => '05', 'identification_number' => '0912345678', 'name' => 'Carlos Mendoza', 'email' => 'carlos.mendoza@example.com'],
            ['identification_type' => '07', 'identification_number' => '9999999999999', 'name' => 'Consumidor Final', 'email' => null],
        ];

        Company::all()->each(function (Company $company) use ($products, $customers) {
            foreach ($products as $product) {
                $company->products()->updateOrCreate(
                    ['code' => $product['code']],
                    [
                        'name' => $product['name'],
                        'unit_price' => $product['unit_price'],
                        'tax_rate' => $product['tax_rate'],
                        'is_active' => true,
                    ]
                );
            }

            foreach ($customers as $customer) {
                $company->customers()->updateOrCreate(
                    ['identification_number' => $customer['identification_number']],
                    [
                        'identification_type' => $customer['identification_type'],
                        'name' => $customer['name'],
                        'email' => $customer['email'],
                    ]
                );
            }
        });
    }
}
