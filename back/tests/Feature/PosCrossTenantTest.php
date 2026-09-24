<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\PosCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mirrors the structure of EstablishmentCrossTenantTest / CustomerProductCrossTenantTest:
 * two companies, a user with active membership only in Company A, cross-tenant attempts
 * against Company B's PosCategory resources, plus a positive control on A's own data.
 */
class PosCrossTenantTest extends TestCase
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

    public function test_company_a_cannot_view_pos_categories_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999801', 'posx1a@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999802', 'posx1b@example.com');

        PosCategory::query()->create(['company_id' => $companyB->id, 'name' => 'Secreta B']);

        Sanctum::actingAs($userA);

        $this->getJson("/api/companies/{$companyA->id}/pos-categories")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_company_a_cannot_list_pos_categories_directly_under_company_b_url(): void
    {
        // Regression guard for the class-level viewAny() gap (authorizeResource
        // maps index -> viewAny, which has no model instance to scope against):
        // a user who only belongs to Company A must not be able to list Company
        // B's categories just by putting B's id in the URL.
        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999808', 'posx5a@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999809', 'posx5b@example.com');

        PosCategory::query()->create(['company_id' => $companyB->id, 'name' => 'Secreta B']);

        Sanctum::actingAs($userA);

        $this->getJson("/api/companies/{$companyB->id}/pos-categories")
            ->assertForbidden();
    }

    public function test_company_a_cannot_view_update_or_delete_a_specific_pos_category_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999803', 'posx2a@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999804', 'posx2b@example.com');

        $categoryB = PosCategory::query()->create(['company_id' => $companyB->id, 'name' => 'Categoria B']);

        Sanctum::actingAs($userA);

        $this->getJson("/api/companies/{$companyA->id}/pos-categories/{$categoryB->id}")
            ->assertNotFound();

        $this->putJson("/api/companies/{$companyA->id}/pos-categories/{$categoryB->id}", [
            'name' => 'Hacked',
        ])->assertNotFound();

        $this->deleteJson("/api/companies/{$companyA->id}/pos-categories/{$categoryB->id}")
            ->assertNotFound();

        $this->assertSame('Categoria B', $categoryB->fresh()->name);
    }

    public function test_company_a_cannot_create_pos_category_directly_under_company_b_url(): void
    {
        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999805', 'posx3a@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999806', 'posx3b@example.com');

        Sanctum::actingAs($userA);

        $this->postJson("/api/companies/{$companyB->id}/pos-categories", [
            'name' => 'Intrusa',
        ])->assertForbidden();

        $this->assertDatabaseMissing('pos_categories', ['name' => 'Intrusa']);
    }

    public function test_company_a_can_manage_its_own_pos_categories(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999807', 'posx4a@example.com');
        Sanctum::actingAs($userA);

        $id = $this->postJson("/api/companies/{$companyA->id}/pos-categories", [
            'name' => 'Bebidas',
        ])->assertCreated()->json('id');

        $this->getJson("/api/companies/{$companyA->id}/pos-categories/{$id}")
            ->assertOk()
            ->assertJsonPath('name', 'Bebidas');

        $this->putJson("/api/companies/{$companyA->id}/pos-categories/{$id}", [
            'name' => 'Bebidas frias',
        ])->assertOk()->assertJsonPath('name', 'Bebidas frias');

        $this->deleteJson("/api/companies/{$companyA->id}/pos-categories/{$id}")
            ->assertNoContent();
    }
}
