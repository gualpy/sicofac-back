<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyEmissionPoint;
use App\Models\CompanyEstablishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmissionPointCrossTenantTest extends TestCase
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

    /**
     * Attack shape 1: Company A uses ITS OWN (valid) company id in the URL,
     * but points {establishment} at Company B's establishment id. Nested
     * route-model-binding (scopeBindings) should fail to resolve the
     * establishment under company A's relation -> 404, before any policy
     * or controller code runs.
     */
    public function test_company_a_cannot_create_emission_point_under_company_b_establishment_via_own_company_url(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999501', 'a1ep@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999502', 'b1ep@example.com');

        $establishmentB = CompanyEstablishment::query()->create([
            'company_id' => $companyB->id,
            'code' => '001',
            'name' => 'Matriz B',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $this->postJson(
            "/api/companies/{$companyA->id}/establishments/{$establishmentB->id}/emission-points",
            ['code' => '001']
        )->assertNotFound();

        $this->assertDatabaseCount('company_emission_points', 0);
    }

    /**
     * Attack shape 2: Company A's user authenticates against Company B's
     * OWN url segment (correct company/establishment pairing) while not
     * being a member of B at all. Even if binding resolves the
     * establishment correctly (it does belong to that company id), the
     * FormRequest::authorize() re-derives the company from the real
     * $establishment->company relation, not the URL, and must still deny.
     */
    public function test_user_not_member_of_company_b_cannot_create_emission_point_via_companyB_own_url(): void
    {
        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999503', 'a2ep@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999504', 'b2ep@example.com');

        $establishmentB = CompanyEstablishment::query()->create([
            'company_id' => $companyB->id,
            'code' => '001',
            'name' => 'Matriz B',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $this->postJson(
            "/api/companies/{$companyB->id}/establishments/{$establishmentB->id}/emission-points",
            ['code' => '001']
        )->assertForbidden();

        $this->assertDatabaseCount('company_emission_points', 0);
    }

    public function test_company_a_cannot_update_emission_point_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999505', 'a3ep@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999506', 'b3ep@example.com');

        $establishmentB = CompanyEstablishment::query()->create([
            'company_id' => $companyB->id,
            'code' => '001',
            'name' => 'Matriz B',
            'is_active' => true,
        ]);
        $emissionPointB = CompanyEmissionPoint::query()->create([
            'company_establishment_id' => $establishmentB->id,
            'code' => '001',
            'description' => 'Original B',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $this->patchJson(
            "/api/companies/{$companyA->id}/establishments/{$establishmentB->id}/emission-points/{$emissionPointB->id}",
            ['description' => 'Hacked']
        )->assertNotFound();

        $this->assertSame('Original B', $emissionPointB->fresh()->description);
    }

    public function test_company_a_can_manage_emission_points_of_its_own_establishment(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999507', 'a4ep@example.com');

        $establishmentA = CompanyEstablishment::query()->create([
            'company_id' => $companyA->id,
            'code' => '001',
            'name' => 'Matriz A',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $emissionPointId = $this->postJson(
            "/api/companies/{$companyA->id}/establishments/{$establishmentA->id}/emission-points",
            ['code' => '001', 'description' => 'Punto A']
        )->assertCreated()->json('id');

        $this->patchJson(
            "/api/companies/{$companyA->id}/establishments/{$establishmentA->id}/emission-points/{$emissionPointId}",
            ['description' => 'Punto A actualizado']
        )->assertOk()->assertJsonPath('description', 'Punto A actualizado');
    }
}
