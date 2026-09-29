<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_import_products_and_get_processing_response(): void
    {
        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $csv = implode("\n", [
            'sku,name,price,tax_rate,is_active',
            'SKU-1,Producto A,10,15,1',
            'SKU-2,Producto B,5.50,0,0',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $response = $this->postJson("/api/companies/{$company->id}/products/import", [
            'file' => $file,
        ])->assertStatus(202)
            ->assertJsonPath('status', 'processing')
            ->assertJsonStructure(['import_id']);

        $importId = (int) $response->json('import_id');

        $import = ProductImport::query()->findOrFail($importId);
        $this->assertSame($company->id, $import->company_id);
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->valid_count);
        $this->assertSame(0, $import->invalid_count);

        $this->assertDatabaseHas('products', [
            'company_id' => $company->id,
            'code' => 'SKU-1',
        ]);
    }

    public function test_import_derives_tax_code_from_tax_rate(): void
    {
        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $csv = implode("\n", [
            'sku,name,price,tax_rate,is_active',
            'SKU-15,Producto quince,10,15,1',
            'SKU-5,Producto cinco,10,5,1',
            'SKU-0,Producto cero,10,0,1',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson("/api/companies/{$company->id}/products/import", [
            'file' => $file,
        ])->assertStatus(202);

        $this->assertSame('15', Product::query()->where('code', 'SKU-15')->firstOrFail()->tax_code->value);
        $this->assertSame('5', Product::query()->where('code', 'SKU-5')->firstOrFail()->tax_code->value);
        $this->assertSame('0', Product::query()->where('code', 'SKU-0')->firstOrFail()->tax_code->value);

        // Re-importing with a changed rate must also refresh tax_code on update,
        // not just leave it stuck at whatever it was set to on creation.
        $csvUpdate = implode("\n", [
            'sku,name,price,tax_rate,is_active',
            'SKU-15,Producto quince,10,5,1',
        ]);
        $this->postJson("/api/companies/{$company->id}/products/import", [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csvUpdate),
        ])->assertStatus(202);

        $this->assertSame('5', Product::query()->where('code', 'SKU-15')->firstOrFail()->tax_code->value);
    }

    public function test_seller_cannot_import_products(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller Import',
            'email' => 'seller-import@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Import Seller Co',
            'ruc' => '0999999999009',
            'environment' => 'test',
        ]);

        $seller->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Seller->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($seller);

        $csv = implode("\n", [
            'sku,name,price,tax_rate',
            'SKU-1,Producto,10,15',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson("/api/companies/{$company->id}/products/import", [
            'file' => $file,
        ])->assertForbidden();
    }

    public function test_invalid_rows_are_counted_in_import_result(): void
    {
        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $csv = implode("\n", [
            'sku,name,price,tax_rate',
            'SKU-1,Producto A,10,15',
            ',Producto Invalido,4,12',
            'SKU-3,Producto C,-1,15',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $importId = (int) $this->postJson("/api/companies/{$company->id}/products/import", [
            'file' => $file,
        ])->assertStatus(202)->json('import_id');

        $import = ProductImport::query()->findOrFail($importId);

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->valid_count);
        $this->assertSame(2, $import->invalid_count);
        $this->assertCount(2, $import->errors_json ?? []);
    }

    public function test_cross_company_import_is_blocked(): void
    {
        [$owner, $companyA] = $this->ownerCompany();

        $companyB = Company::query()->create([
            'name' => 'Other Company',
            'ruc' => '0999999999010',
            'environment' => 'test',
        ]);

        Sanctum::actingAs($owner);

        $csv = implode("\n", [
            'sku,name,price,tax_rate',
            'SKU-1,Producto,10,15',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->postJson("/api/companies/{$companyB->id}/products/import", [
            'file' => $file,
        ])->assertForbidden();

        $this->assertSame(0, ProductImport::query()->count());
        $this->assertSame(0, Product::query()->where('company_id', $companyB->id)->count());
    }

    public function test_import_listing_only_shows_company_imports(): void
    {
        [$owner, $companyA] = $this->ownerCompany();
        $otherOwner = User::query()->create([
            'name' => 'Owner Other',
            'email' => 'owner-other@example.com',
            'password' => 'password',
        ]);
        $companyB = Company::query()->create([
            'name' => 'Import Company B',
            'ruc' => '0999999999020',
            'environment' => 'test',
        ]);
        $otherOwner->companies()->attach($companyB->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        ProductImport::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $owner->id,
            'status' => 'completed',
            'valid_count' => 1,
            'invalid_count' => 0,
            'errors_json' => [],
        ]);
        ProductImport::query()->create([
            'company_id' => $companyB->id,
            'user_id' => $otherOwner->id,
            'status' => 'completed',
            'valid_count' => 2,
            'invalid_count' => 0,
            'errors_json' => [],
        ]);

        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/companies/{$companyA->id}/products/imports")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($companyA->id, $response->json('data.0.company_id'));
    }

    public function test_import_detail_blocks_cross_company_access(): void
    {
        [$owner, $companyA] = $this->ownerCompany();
        $companyB = Company::query()->create([
            'name' => 'Import Company B2',
            'ruc' => '0999999999021',
            'environment' => 'test',
        ]);

        $importB = ProductImport::query()->create([
            'company_id' => $companyB->id,
            'user_id' => null,
            'status' => 'completed',
            'valid_count' => 1,
            'invalid_count' => 1,
            'errors_json' => [['row' => 2, 'messages' => ['Invalid']]],
        ]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/companies/{$companyA->id}/products/imports/{$importB->id}")
            ->assertNotFound();
    }

    public function test_xlsx_is_rejected_gracefully_when_extensions_missing(): void
    {
        if (extension_loaded('zip') && extension_loaded('gd')) {
            $this->markTestSkipped('zip and gd extensions are enabled.');
        }

        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $file = UploadedFile::fake()->create('products.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson("/api/companies/{$company->id}/products/import", [
            'file' => $file,
        ])->assertStatus(422);

        $errors = $response->json('errors.file', []);
        $message = implode(' ', is_array($errors) ? $errors : []);
        $this->assertStringContainsString('Please upload CSV', $message);
    }

    /**
     * @return array{User, Company}
     */
    private function ownerCompany(): array
    {
        $owner = User::query()->create([
            'name' => 'Owner Import',
            'email' => 'owner-import@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Import Company',
            'ruc' => '0999999999008',
            'environment' => 'test',
        ]);

        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return [$owner, $company];
    }
}
