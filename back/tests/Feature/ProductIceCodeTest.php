<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductIceCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_product_with_ice_rate_requires_ice_code(): void
    {
        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $this->postJson("/api/companies/{$company->id}/products", [
            'code' => 'ICE-1',
            'name' => 'Bebida gaseosa',
            'unit_price' => 1.50,
            'tax_rate' => 15,
            'ice_rate' => 50,
        ])->assertStatus(422)->assertJsonValidationErrors(['ice_code']);
    }

    public function test_creating_a_product_with_ice_rate_and_valid_ice_code_succeeds(): void
    {
        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $this->postJson("/api/companies/{$company->id}/products", [
            'code' => 'ICE-2',
            'name' => 'Bebida gaseosa',
            'unit_price' => 1.50,
            'tax_rate' => 15,
            'ice_rate' => 50,
            'ice_code' => '3072',
        ])->assertCreated()
            ->assertJsonPath('ice_code', '3072');

        $this->assertDatabaseHas('products', [
            'code' => 'ICE-2',
            'ice_code' => '3072',
        ]);
    }

    public function test_creating_a_product_without_ice_rate_does_not_require_ice_code(): void
    {
        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $this->postJson("/api/companies/{$company->id}/products", [
            'code' => 'ICE-3',
            'name' => 'Producto normal',
            'unit_price' => 10,
            'tax_rate' => 15,
        ])->assertCreated();
    }

    public function test_draft_invoice_persists_item_ice_code(): void
    {
        [$owner, $company] = $this->ownerCompany();
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [
                [
                    'code' => 'ICE-4',
                    'name' => 'Bebida gaseosa',
                    'quantity' => 2,
                    'unit_price' => 1.50,
                    'tax_rate' => 15,
                    'tax_code' => '15',
                    'ice_rate' => 50,
                    'ice_code' => '3072',
                ],
            ],
        ])->assertCreated();

        $invoiceId = $response->json('id');

        $this->assertDatabaseHas('invoice_items', [
            'invoice_id' => $invoiceId,
            'code' => 'ICE-4',
            'ice_code' => '3072',
        ]);
    }

    /**
     * @return array{User, Company}
     */
    private function ownerCompany(): array
    {
        $owner = User::query()->create([
            'name' => 'Owner Ice',
            'email' => 'owner-ice@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Ice Company',
            'ruc' => '0999999999009',
            'environment' => 'test',
        ]);

        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return [$owner, $company];
    }
}
