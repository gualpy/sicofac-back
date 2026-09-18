<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyWithCustomer(): array
    {
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester@example.com',
            'password' => 'password',
        ]);
        Sanctum::actingAs($user);

        $company = Company::query()->create([
            'name' => 'Acme SA',
            'ruc' => '0999999999001',
            'environment' => 'test',
        ]);
        $user->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        $this->postJson("/api/companies/{$company->id}/customers", [
            'name' => 'Juan Perez',
            'identification_type' => '05',
            'identification_number' => '1712345678',
        ])->assertCreated();

        return [$company];
    }

    public function test_search_matches_a_single_character_term(): void
    {
        [$company] = $this->makeCompanyWithCustomer();

        $response = $this->getJson("/api/companies/{$company->id}/customers?search=7");

        $response->assertOk();
        $this->assertSame('Juan Perez', $response->json('data.0.name'));
    }

    public function test_search_matches_the_identification_number_pasted_with_its_type_prefix(): void
    {
        [$company] = $this->makeCompanyWithCustomer();

        // The customers list displays "{tipo} {numero}" (e.g. "05 1712345678");
        // pasting that exact string back into the search must still match.
        $response = $this->getJson("/api/companies/{$company->id}/customers?search=" . urlencode('05 1712345678'));

        $response->assertOk();
        $this->assertSame('Juan Perez', $response->json('data.0.name'));
    }
}
