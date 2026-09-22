<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BillingAuthorizationTest already covers cross-company READ of a Customer
 * and the "referenced by invoice" delete guard for both resources. This
 * file fills the remaining gap: direct cross-tenant Product show/update and
 * Customer update, which previously only had indirect coverage via the
 * import/export test suites.
 */
class CustomerProductCrossTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Company, 1: User}
     */
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

    public function test_company_a_cannot_view_or_update_product_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999601', 'a1cp@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999602', 'b1cp@example.com');

        $productB = Product::query()->create([
            'company_id' => $companyB->id,
            'code' => 'SKU-B-1',
            'name' => 'Producto B',
            'unit_price' => 50,
            'tax_rate' => 15,
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);

        $this->getJson("/api/companies/{$companyA->id}/products/{$productB->id}")
            ->assertNotFound();

        $this->putJson("/api/companies/{$companyA->id}/products/{$productB->id}", [
            'name' => 'Hacked name',
        ])->assertNotFound();

        $this->assertSame('Producto B', $productB->fresh()->name);
    }

    public function test_company_a_can_view_and_update_its_own_product(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999603', 'a2cp@example.com');

        Sanctum::actingAs($userA);

        $productId = $this->postJson("/api/companies/{$companyA->id}/products", [
            'code' => 'SKU-A-1',
            'name' => 'Producto A',
            'unit_price' => 30,
            'tax_rate' => 15,
        ])->assertCreated()->json('id');

        $this->getJson("/api/companies/{$companyA->id}/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('name', 'Producto A');

        $this->putJson("/api/companies/{$companyA->id}/products/{$productId}", [
            'name' => 'Producto A actualizado',
            'unit_price' => 30,
            'tax_rate' => 15,
        ])->assertOk()->assertJsonPath('name', 'Producto A actualizado');
    }

    public function test_company_a_cannot_update_customer_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999604', 'a3cp@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999605', 'b3cp@example.com');

        $customerB = Customer::query()->create([
            'company_id' => $companyB->id,
            'name' => 'Cliente B',
            'identification_type' => '05',
            'identification_number' => '0933333333',
        ]);

        Sanctum::actingAs($userA);

        $this->putJson("/api/companies/{$companyA->id}/customers/{$customerB->id}", [
            'name' => 'Hacked customer',
        ])->assertNotFound();

        $this->assertSame('Cliente B', $customerB->fresh()->name);
    }

    public function test_company_a_can_update_its_own_customer(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999606', 'a4cp@example.com');

        Sanctum::actingAs($userA);

        $customerId = $this->postJson("/api/companies/{$companyA->id}/customers", [
            'name' => 'Cliente A',
            'identification_type' => '05',
            'identification_number' => '0944444444',
        ])->assertCreated()->json('id');

        $this->putJson("/api/companies/{$companyA->id}/customers/{$customerId}", [
            'name' => 'Cliente A actualizado',
        ])->assertOk()->assertJsonPath('name', 'Cliente A actualizado');
    }
}
