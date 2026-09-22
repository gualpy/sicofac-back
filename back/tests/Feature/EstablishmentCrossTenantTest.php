<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyEstablishment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EstablishmentCrossTenantTest extends TestCase
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

    public function test_company_a_cannot_list_establishments_of_company_b(): void
    {
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999401', 'b1estab@example.com');
        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999402', 'a1estab@example.com');

        CompanyEstablishment::query()->create([
            'company_id' => $companyB->id,
            'code' => '001',
            'name' => 'Matriz B',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $this->getJson("/api/companies/{$companyB->id}/establishments")
            ->assertForbidden();
    }

    public function test_company_a_can_list_its_own_establishments(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999403', 'a2estab@example.com');

        CompanyEstablishment::query()->create([
            'company_id' => $companyA->id,
            'code' => '001',
            'name' => 'Matriz A',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $response = $this->getJson("/api/companies/{$companyA->id}/establishments")
            ->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('Matriz A', $response->json('0.name'));
    }

    public function test_establishment_listing_never_leaks_another_companys_rows(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999404', 'a3estab@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999405', 'b3estab@example.com');

        CompanyEstablishment::query()->create([
            'company_id' => $companyA->id,
            'code' => '001',
            'name' => 'Matriz A',
            'is_active' => true,
        ]);
        CompanyEstablishment::query()->create([
            'company_id' => $companyB->id,
            'code' => '001',
            'name' => 'Matriz B',
            'is_active' => true,
        ]);

        Sanctum::actingAs($userA);
        $response = $this->getJson("/api/companies/{$companyA->id}/establishments")->assertOk();

        $names = collect($response->json())->pluck('name');
        $this->assertTrue($names->contains('Matriz A'));
        $this->assertFalse($names->contains('Matriz B'));
    }
}
