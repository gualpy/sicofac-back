<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * API keys let a service (e.g. FitOS) call the v1 invoice-issue endpoints
 * without impersonating a human user. A key is a Sanctum token whose
 * tokenable is the Company itself (App\Models\Company uses HasApiTokens),
 * scoped 1-to-1 to that company -- no role/pivot to resolve.
 */
class CompanyApiKeyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Company, 1: User}
     */
    private function makeCompanyWithOwner(string $ruc, string $email, string $role = 'owner'): array
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => $email,
            'password' => 'password',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $company = Company::query()->create([
            'name' => "Gym {$ruc}",
            'ruc' => $ruc,
            'environment' => 'test',
        ]);
        $owner->companies()->attach($company->id, [
            'role' => $role,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return [$company, $owner];
    }

    /**
     * Sanctum::actingAs forces the guard for the rest of the test regardless
     * of any Authorization header sent afterwards, so tests that need to
     * switch to a real API key token log in for real instead.
     */
    private function loginToken(User $user): string
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_owner_can_create_list_and_revoke_an_api_key(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('0999999999801', 'owner1@example.com');

        Sanctum::actingAs($owner);

        $create = $this->postJson("/api/companies/{$company->id}/api-keys", ['name' => 'FitOS - Gym 1'])
            ->assertCreated();

        $this->assertNotEmpty($create->json('token'));
        $tokenId = $create->json('id');

        $list = $this->getJson("/api/companies/{$company->id}/api-keys")->assertOk();
        $this->assertCount(1, $list->json('api_keys'));
        $this->assertSame('FitOS - Gym 1', $list->json('api_keys.0.name'));
        // The plaintext secret is never returned again after creation.
        $this->assertArrayNotHasKey('token', $list->json('api_keys.0'));

        $this->deleteJson("/api/companies/{$company->id}/api-keys/{$tokenId}")->assertNoContent();
        $this->assertCount(0, $this->getJson("/api/companies/{$company->id}/api-keys")->json('api_keys'));
    }

    public function test_non_owner_cannot_create_api_keys(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('0999999999802', 'owner2@example.com');

        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin2@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
        $admin->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Admin->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/companies/{$company->id}/api-keys", ['name' => 'Should fail'])
            ->assertForbidden();
    }

    public function test_api_key_authenticates_and_issues_invoices_for_its_own_company(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('0999999999803', 'owner3@example.com');

        $ownerToken = $this->loginToken($owner);
        $plainTextToken = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/companies/{$company->id}/api-keys", ['name' => 'FitOS'])
            ->assertCreated()
            ->json('token');

        // Real bearer header, no Sanctum::actingAs -- proves the guard
        // resolves the Company tokenable end to end, not just the policy.
        $this->app['auth']->forgetGuards();
        $response = $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->withHeader('Idempotency-Key', 'fitos-key-0001')
            ->postJson("/api/v1/companies/{$company->id}/invoices/issue", [
                'external_reference' => 'fitos:sale:1',
                'document_code' => '01',
                'items' => [
                    ['code' => 'AGUA', 'name' => 'Agua', 'quantity' => 1, 'unit_price' => 1, 'discount' => 0, 'tax_rate' => 15],
                ],
                'payment_methods' => [['method' => 'no_utiliza_sist_financiero', 'value' => 1.15]],
            ]);

        $response->assertStatus(202);
    }

    public function test_api_key_cannot_issue_invoices_for_a_different_company(): void
    {
        [$companyA, $ownerA] = $this->makeCompanyWithOwner('0999999999804', 'owner4a@example.com');
        [$companyB] = $this->makeCompanyWithOwner('0999999999805', 'owner4b@example.com');

        $ownerToken = $this->loginToken($ownerA);
        $plainTextToken = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/companies/{$companyA->id}/api-keys", ['name' => 'FitOS'])
            ->assertCreated()
            ->json('token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->withHeader('Idempotency-Key', 'fitos-key-0002')
            ->postJson("/api/v1/companies/{$companyB->id}/invoices/issue", [
                'external_reference' => 'fitos:sale:2',
                'items' => [
                    ['code' => 'AGUA', 'name' => 'Agua', 'quantity' => 1, 'unit_price' => 1, 'discount' => 0, 'tax_rate' => 15],
                ],
            ])
            ->assertForbidden();
    }

    public function test_api_key_cannot_manage_api_keys_of_its_own_company(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('0999999999806', 'owner5@example.com');

        $ownerToken = $this->loginToken($owner);
        $plainTextToken = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/companies/{$company->id}/api-keys", ['name' => 'FitOS'])
            ->assertCreated()
            ->json('token');

        // A service key can never mint or list keys, not even its own company's.
        // forgetGuards() clears the guard's cached resolved user from the
        // previous request in this test -- otherwise it keeps answering as
        // the owner regardless of which bearer token this request sends.
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->getJson("/api/companies/{$company->id}/api-keys")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->postJson("/api/companies/{$company->id}/api-keys", ['name' => 'Another key'])
            ->assertForbidden();
    }

    public function test_revoked_api_key_no_longer_authenticates(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('0999999999807', 'owner6@example.com');

        $ownerToken = $this->loginToken($owner);
        $create = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/companies/{$company->id}/api-keys", ['name' => 'FitOS'])
            ->assertCreated();
        $plainTextToken = $create->json('token');

        $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->deleteJson("/api/companies/{$company->id}/api-keys/{$create->json('id')}")
            ->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->withHeader('Idempotency-Key', 'fitos-key-0003')
            ->postJson("/api/v1/companies/{$company->id}/invoices/issue", [
                'external_reference' => 'fitos:sale:3',
                'items' => [
                    ['code' => 'AGUA', 'name' => 'Agua', 'quantity' => 1, 'unit_price' => 1, 'discount' => 0, 'tax_rate' => 15],
                ],
            ])
            ->assertStatus(401);
    }

    public function test_by_reference_lookup_works_with_api_key_scoped_to_its_company(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('0999999999808', 'owner7@example.com');

        $ownerToken = $this->loginToken($owner);
        $plainTextToken = $this->withHeader('Authorization', "Bearer {$ownerToken}")
            ->postJson("/api/companies/{$company->id}/api-keys", ['name' => 'FitOS'])
            ->assertCreated()
            ->json('token');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->withHeader('Idempotency-Key', 'fitos-key-0004')
            ->postJson("/api/v1/companies/{$company->id}/invoices/issue", [
                'external_reference' => 'fitos:sale:4',
                'items' => [
                    ['code' => 'AGUA', 'name' => 'Agua', 'quantity' => 1, 'unit_price' => 1, 'discount' => 0, 'tax_rate' => 15],
                ],
            ])
            ->assertStatus(202);

        $this->withHeader('Authorization', "Bearer {$plainTextToken}")
            ->getJson("/api/v1/companies/{$company->id}/invoices/by-reference/fitos:sale:4")
            ->assertOk();
    }
}
