<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\Billing\BuildXmlJob;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceCrossTenantTest extends TestCase
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

    private function makeDraftInvoice(Company $company): int
    {
        return $this->postJson("/api/companies/{$company->id}/invoices", [
            'document_code' => '01',
            'items' => [[
                'code' => 'SKU-X-1',
                'name' => 'Item X',
                'quantity' => 1,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ])->assertCreated()->json('id');
    }

    public function test_company_a_cannot_view_invoice_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999101', 'a1@example.com');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999102', 'b1@example.com');

        Sanctum::actingAs($userB);
        $invoiceBId = $this->makeDraftInvoice($companyB);

        Sanctum::actingAs($userA);
        $this->getJson("/api/companies/{$companyA->id}/invoices/{$invoiceBId}")
            ->assertNotFound();
    }

    public function test_company_a_can_view_its_own_invoice(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999103', 'a2@example.com');

        Sanctum::actingAs($userA);
        $invoiceAId = $this->makeDraftInvoice($companyA);

        $this->getJson("/api/companies/{$companyA->id}/invoices/{$invoiceAId}")
            ->assertOk()
            ->assertJsonPath('id', $invoiceAId);
    }

    public function test_company_a_cannot_update_draft_invoice_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999104', 'a3@example.com');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999105', 'b3@example.com');

        Sanctum::actingAs($userB);
        $invoiceBId = $this->makeDraftInvoice($companyB);
        $before = Invoice::query()->findOrFail($invoiceBId)->toArray();

        Sanctum::actingAs($userA);
        $this->patchJson("/api/companies/{$companyA->id}/invoices/{$invoiceBId}", [
            'items' => [[
                'code' => 'SKU-HACK-1',
                'name' => 'Hacked item',
                'quantity' => 99,
                'unit_price' => 999,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ])->assertNotFound();

        $after = Invoice::query()->findOrFail($invoiceBId)->fresh(['items'])->toArray();
        $this->assertSame($before['total'], $after['total']);
        $this->assertSame($before['updated_at'], $after['updated_at']);
    }

    public function test_company_a_can_update_its_own_draft_invoice(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999106', 'a4@example.com');

        Sanctum::actingAs($userA);
        $invoiceAId = $this->makeDraftInvoice($companyA);

        $this->patchJson("/api/companies/{$companyA->id}/invoices/{$invoiceAId}", [
            'items' => [[
                'code' => 'SKU-X-2',
                'name' => 'Item X updated',
                'quantity' => 2,
                'unit_price' => 10,
                'discount' => 0,
                'tax_rate' => 15,
            ]],
        ])->assertOk();

        $this->assertSame(20.0, (float) Invoice::query()->findOrFail($invoiceAId)->subtotal);
    }

    public function test_company_a_cannot_delete_draft_invoice_of_company_b(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999107', 'a5@example.com');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999108', 'b5@example.com');

        Sanctum::actingAs($userB);
        $invoiceBId = $this->makeDraftInvoice($companyB);

        Sanctum::actingAs($userA);
        $this->deleteJson("/api/companies/{$companyA->id}/invoices/{$invoiceBId}")
            ->assertNotFound();

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceBId,
            'deleted_at' => null,
        ]);
    }

    public function test_company_a_can_delete_its_own_draft_invoice(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999109', 'a6@example.com');

        Sanctum::actingAs($userA);
        $invoiceAId = $this->makeDraftInvoice($companyA);

        $this->deleteJson("/api/companies/{$companyA->id}/invoices/{$invoiceAId}")
            ->assertNoContent();

        $this->assertSoftDeleted('invoices', ['id' => $invoiceAId]);
    }

    public function test_company_a_cannot_emit_invoice_of_company_b(): void
    {
        Bus::fake();

        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999110', 'a7@example.com');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999111', 'b7@example.com');

        Sanctum::actingAs($userB);
        $invoiceBId = $this->makeDraftInvoice($companyB);
        $before = Invoice::query()->findOrFail($invoiceBId);
        $this->assertNull($before->issue_requested_at);

        Sanctum::actingAs($userA);
        $this->postJson("/api/companies/{$companyA->id}/invoices/{$invoiceBId}/emit")
            ->assertNotFound();

        $after = Invoice::query()->findOrFail($invoiceBId);
        $this->assertSame(InvoiceStatus::Draft, $after->status);
        $this->assertNull($after->issue_requested_at);

        Bus::assertNotDispatched(BuildXmlJob::class);
    }

    public function test_company_a_can_emit_its_own_invoice(): void
    {
        Bus::fake();

        [$companyA, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999112', 'a8@example.com');

        Sanctum::actingAs($userA);
        $invoiceAId = $this->makeDraftInvoice($companyA);

        $this->postJson("/api/companies/{$companyA->id}/invoices/{$invoiceAId}/emit")
            ->assertOk();

        $after = Invoice::query()->findOrFail($invoiceAId);
        $this->assertSame(InvoiceStatus::Processing, $after->status);
        $this->assertNotNull($after->issue_requested_at);

        Bus::assertDispatched(BuildXmlJob::class);
    }

    /**
     * Even if Company A somehow authenticated a user against Company B's own
     * URL segment (e.g. a stolen/mistyped company id in the path) without
     * being a member of B, the invoice policy re-derives authorization from
     * the invoice's real company_id/membership rather than trusting the URL.
     */
    public function test_user_not_member_of_company_b_cannot_emit_via_companyB_own_url(): void
    {
        Bus::fake();

        [, $userA] = $this->makeCompanyWithOwner('Company A', '0999999999113', 'a9@example.com');
        [$companyB, $userB] = $this->makeCompanyWithOwner('Company B', '0999999999114', 'b9@example.com');

        Sanctum::actingAs($userB);
        $invoiceBId = $this->makeDraftInvoice($companyB);

        Sanctum::actingAs($userA);
        $this->postJson("/api/companies/{$companyB->id}/invoices/{$invoiceBId}/emit")
            ->assertForbidden();

        Bus::assertNotDispatched(BuildXmlJob::class);
    }
}
