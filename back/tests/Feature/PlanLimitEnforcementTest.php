<?php

namespace Tests\Feature;

use App\DTOs\Billing\InvoiceItemData;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\InvoiceDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hard caps (tope duro) per plan: documentos/mes and usuarios. A plan with a
 * null limit column (Empresa) is unlimited; a company with no plan_id at all
 * (legacy, pre-plans) is also unlimited.
 */
class PlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(?string $planSlug, User $owner): Company
    {
        $planId = $planSlug ? Plan::query()->where('slug', $planSlug)->value('id') : null;

        $company = Company::query()->create([
            'name' => 'Starter Co',
            'trade_name' => 'Starter Co',
            'ruc' => '0999999999001',
            'environment' => 'test',
            'sri_signing_enabled' => false,
            'sri_submission_enabled' => false,
            'plan_id' => $planId,
        ]);

        $owner->companies()->attach($company->id, [
            'role' => 'owner',
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        return $company;
    }

    private function createDrafts(Company $company, int $count): void
    {
        $drafts = app(InvoiceDraftService::class);

        for ($i = 0; $i < $count; $i++) {
            $drafts->create(
                companyId: $company->id,
                customerId: null,
                items: [InvoiceItemData::fromArray(['code' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 10, 'discount' => 0, 'tax_rate' => 15])],
            );
        }
    }

    public function test_starter_plan_blocks_the_51st_document_this_month(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'password']);
        $company = $this->makeCompany('starter', $owner);
        Sanctum::actingAs($owner);

        $this->createDrafts($company, 50);

        $response = $this->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['code' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $response->assertStatus(403);
        $this->assertSame('plan_document_limit_reached', $response->json('error.code'));
    }

    public function test_starter_plan_allows_up_to_its_document_limit(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner2@example.com', 'password' => 'password']);
        $company = $this->makeCompany('starter', $owner);
        Sanctum::actingAs($owner);

        $this->createDrafts($company, 49);

        $response = $this->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['code' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $response->assertStatus(201);
    }

    public function test_company_without_a_plan_is_unlimited(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner3@example.com', 'password' => 'password']);
        $company = $this->makeCompany(null, $owner);
        Sanctum::actingAs($owner);

        $this->createDrafts($company, 60);

        $response = $this->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['code' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $response->assertStatus(201);
    }

    public function test_starter_plan_blocks_inviting_a_third_user(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'owner4@example.com', 'password' => 'password']);
        $company = $this->makeCompany('starter', $owner);
        Sanctum::actingAs($owner);

        // owner (1) + this invite (2) fills the 2-user Starter cap.
        $this->postJson("/api/companies/{$company->id}/invitations", [
            'email' => 'second@example.com',
            'role' => 'admin',
        ])->assertStatus(201);

        $response = $this->postJson("/api/companies/{$company->id}/invitations", [
            'email' => 'third@example.com',
            'role' => 'seller',
        ]);

        $response->assertStatus(403);
        $this->assertSame('plan_user_limit_reached', $response->json('error.code'));
    }
}
