<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\PosCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyWithOwner(string $name, string $ruc, string $email): array
    {
        $owner = User::query()->create([
            'name' => "Owner {$name}",
            'email' => $email,
            'password' => 'password',
        ]);
        $company = Company::query()->create([
            'name' => $name,
            'ruc' => $ruc,
            'environment' => 'test',
        ]);
        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return [$company, $owner];
    }

    public function test_owner_can_crud_pos_categories_for_their_company(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Heladeria', '0999999999701', 'pc1@example.com');
        Sanctum::actingAs($owner);

        $categoryId = $this->postJson("/api/companies/{$company->id}/pos-categories", [
            'name' => 'Helados',
            'sort_order' => 1,
        ])->assertCreated()->json('id');

        $this->getJson("/api/companies/{$company->id}/pos-categories")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Helados');

        $this->putJson("/api/companies/{$company->id}/pos-categories/{$categoryId}", [
            'name' => 'Helados y postres',
        ])->assertOk()->assertJsonPath('name', 'Helados y postres');

        $this->deleteJson("/api/companies/{$company->id}/pos-categories/{$categoryId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('pos_categories', ['id' => $categoryId]);
    }

    public function test_cannot_delete_category_with_products_assigned(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Cafeteria', '0999999999702', 'pc2@example.com');
        Sanctum::actingAs($owner);

        $category = PosCategory::query()->create([
            'company_id' => $company->id,
            'name' => 'Cafe',
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'code' => 'SKU-CAFE-1',
            'name' => 'Cafe americano',
            'unit_price' => 1.5,
            'tax_rate' => 15,
            'pos_enabled' => true,
            'pos_category_id' => $category->id,
        ]);

        $this->deleteJson("/api/companies/{$company->id}/pos-categories/{$category->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('pos_categories', ['id' => $category->id]);
    }

    public function test_category_name_is_unique_per_company_but_not_globally(): void
    {
        [$companyA, $ownerA] = $this->makeCompanyWithOwner('Company A', '0999999999703', 'pc3a@example.com');
        [$companyB, $ownerB] = $this->makeCompanyWithOwner('Company B', '0999999999704', 'pc3b@example.com');

        Sanctum::actingAs($ownerA);
        $this->postJson("/api/companies/{$companyA->id}/pos-categories", ['name' => 'Bebidas'])
            ->assertCreated();

        $this->postJson("/api/companies/{$companyA->id}/pos-categories", ['name' => 'Bebidas'])
            ->assertStatus(422);

        Sanctum::actingAs($ownerB);
        $this->postJson("/api/companies/{$companyB->id}/pos-categories", ['name' => 'Bebidas'])
            ->assertCreated();
    }

    public function test_product_index_filters_by_pos_enabled(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Tienda', '0999999999705', 'pc4@example.com');
        Sanctum::actingAs($owner);

        Product::query()->create([
            'company_id' => $company->id,
            'code' => 'SKU-POS-1',
            'name' => 'Producto POS',
            'unit_price' => 2,
            'tax_rate' => 15,
            'pos_enabled' => true,
        ]);
        Product::query()->create([
            'company_id' => $company->id,
            'code' => 'SKU-BACK-1',
            'name' => 'Producto oficina',
            'unit_price' => 2,
            'tax_rate' => 15,
            'pos_enabled' => false,
        ]);

        $this->getJson("/api/companies/{$company->id}/products?pos_enabled=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SKU-POS-1');
    }

    public function test_product_cannot_be_assigned_to_pos_category_of_another_company(): void
    {
        [$companyA, $ownerA] = $this->makeCompanyWithOwner('Company A', '0999999999706', 'pc5a@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999707', 'pc5b@example.com');

        $categoryB = PosCategory::query()->create([
            'company_id' => $companyB->id,
            'name' => 'Categoria B',
        ]);

        Sanctum::actingAs($ownerA);

        $this->postJson("/api/companies/{$companyA->id}/products", [
            'code' => 'SKU-XT-1',
            'name' => 'Producto A',
            'unit_price' => 2,
            'tax_rate' => 15,
            'pos_enabled' => true,
            'pos_category_id' => $categoryB->id,
        ])->assertStatus(422);
    }
}
