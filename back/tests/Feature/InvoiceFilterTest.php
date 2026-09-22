<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceFilterTest extends TestCase
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

    private function makeDraftInvoice(Company $company, ?int $customerId = null): int
    {
        return $this->postJson("/api/companies/{$company->id}/invoices", [
            'customer_id' => $customerId,
            'document_code' => '01',
            'items' => [[
                'code' => 'SKU-F-1',
                'name' => 'Item filtro',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ])->assertCreated()->json('id');
    }

    public function test_status_filter_scopes_to_requested_statuses_and_company(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Filtro A', '0999999991001', 'filtro-a@example.com');
        [$otherCompany, $otherOwner] = $this->makeCompanyWithOwner('Filtro B', '0999999992001', 'filtro-b@example.com');

        Sanctum::actingAs($owner);
        $draftId = $this->makeDraftInvoice($company);
        $authorizedId = $this->makeDraftInvoice($company);
        \App\Models\Invoice::query()->whereKey($authorizedId)->update(['status' => InvoiceStatus::Authorized->value]);

        Sanctum::actingAs($otherOwner);
        $this->makeDraftInvoice($otherCompany);

        Sanctum::actingAs($owner);
        $response = $this->getJson("/api/companies/{$company->id}/invoices?status[]=draft")->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($draftId, $ids);
        $this->assertNotContains($authorizedId, $ids);
        $this->assertCount(1, $ids);
    }

    public function test_search_matches_customer_name_and_sequential(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Filtro C', '0999999993001', 'filtro-c@example.com');
        Sanctum::actingAs($owner);

        $customerId = $this->postJson("/api/companies/{$company->id}/customers", [
            'name' => 'Comercial Andina S.A.',
            'identification_type' => '04',
            'identification_number' => '0999999994001',
        ])->assertCreated()->json('id');

        $matchingId = $this->makeDraftInvoice($company, $customerId);
        $otherId = $this->makeDraftInvoice($company);

        $response = $this->getJson("/api/companies/{$company->id}/invoices?search=Andina")->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($matchingId, $ids);
        $this->assertNotContains($otherId, $ids);
    }

    public function test_search_does_not_leak_other_company_customers(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Filtro D', '0999999995001', 'filtro-d@example.com');
        [$otherCompany, $otherOwner] = $this->makeCompanyWithOwner('Filtro E', '0999999996001', 'filtro-e@example.com');

        Sanctum::actingAs($otherOwner);
        $otherCustomerId = $this->postJson("/api/companies/{$otherCompany->id}/customers", [
            'name' => 'Cliente Exclusivo B',
            'identification_type' => '04',
            'identification_number' => '0999999997001',
        ])->assertCreated()->json('id');
        $this->makeDraftInvoice($otherCompany, $otherCustomerId);

        Sanctum::actingAs($owner);
        $this->makeDraftInvoice($company);

        $response = $this->getJson("/api/companies/{$company->id}/invoices?search=Exclusivo")->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    public function test_date_range_filters_by_issue_date(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Filtro F', '0999999998001', 'filtro-f@example.com');
        Sanctum::actingAs($owner);

        $oldId = $this->makeDraftInvoice($company);
        \App\Models\Invoice::query()->whereKey($oldId)->update(['issue_date' => '2020-01-01']);

        $recentId = $this->makeDraftInvoice($company);

        $today = now()->toDateString();
        $response = $this->getJson("/api/companies/{$company->id}/invoices?from={$today}&to={$today}")->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($recentId, $ids);
        $this->assertNotContains($oldId, $ids);
    }

    public function test_per_page_is_respected(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Filtro G', '0999999999101', 'filtro-g@example.com');
        Sanctum::actingAs($owner);

        for ($i = 0; $i < 3; $i++) {
            $this->makeDraftInvoice($company);
        }

        $response = $this->getJson("/api/companies/{$company->id}/invoices?per_page=2")->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('total'));
    }
}
