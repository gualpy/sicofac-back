<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_only_includes_company_products(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner Export',
            'email' => 'owner-export@example.com',
            'password' => 'password',
        ]);

        $companyA = Company::query()->create([
            'name' => 'Company A',
            'ruc' => '0999999999011',
            'environment' => 'test',
        ]);

        $companyB = Company::query()->create([
            'name' => 'Company B',
            'ruc' => '0999999999012',
            'environment' => 'test',
        ]);

        $owner->companies()->attach($companyA->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Product::query()->create([
            'company_id' => $companyA->id,
            'code' => 'A-001',
            'name' => 'Producto A',
            'unit_price' => 10,
            'tax_rate' => 15,
            'is_active' => true,
        ]);

        Product::query()->create([
            'company_id' => $companyB->id,
            'code' => 'B-001',
            'name' => 'Producto B',
            'unit_price' => 20,
            'tax_rate' => 15,
            'is_active' => true,
        ]);

        Sanctum::actingAs($owner);

        $response = $this->get("/api/companies/{$companyA->id}/products/export");

        $response->assertOk();
        $response->assertDownload("products_company_{$companyA->id}.csv");

        $file = $response->baseResponse->getFile();
        $content = file_get_contents($file->getPathname());

        $this->assertIsString($content);
        $this->assertStringContainsString('A-001', $content);
        $this->assertStringNotContainsString('B-001', $content);
    }

    public function test_seller_cannot_export_products(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller Export',
            'email' => 'seller-export@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Export Co',
            'ruc' => '0999999999013',
            'environment' => 'test',
        ]);

        $seller->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Seller->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($seller);

        $this->get("/api/companies/{$company->id}/products/export")
            ->assertForbidden();
    }
}
