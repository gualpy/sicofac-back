<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceRideTest extends TestCase
{
    use RefreshDatabase;

    private function makeAuthorizedInvoice(): array
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

        $customerId = $this->postJson("/api/companies/{$company->id}/customers", [
            'name' => 'Cliente Uno',
            'identification_type' => '05',
            'identification_number' => '0912345678',
        ])->assertCreated()->json('id');

        $this->postJson("/api/companies/{$company->id}/products", [
            'code' => 'SKU-1',
            'name' => 'Producto A',
            'unit_price' => 20,
            'tax_rate' => 15,
            'is_active' => true,
        ])->assertCreated();

        $invoiceId = $this->postJson("/api/companies/{$company->id}/invoices", [
            'customer_id' => $customerId,
            'document_code' => '01',
            'items' => [
                ['code' => 'SKU-1', 'name' => 'Producto A', 'quantity' => 2, 'unit_price' => 20, 'discount' => 0, 'tax_rate' => 15],
            ],
        ])->assertCreated()->json('id');

        return [$company, $invoiceId];
    }

    public function test_ride_downloads_a_pdf_for_an_authorized_invoice(): void
    {
        [$company, $invoiceId] = $this->makeAuthorizedInvoice();

        $this->postJson("/api/companies/{$company->id}/invoices/{$invoiceId}/emit")->assertOk();

        $response = $this->get("/api/companies/{$company->id}/invoices/{$invoiceId}/ride");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_ride_refuses_for_a_non_authorized_invoice(): void
    {
        [$company, $invoiceId] = $this->makeAuthorizedInvoice();

        // Left as draft: never emitted.
        $this->get("/api/companies/{$company->id}/invoices/{$invoiceId}/ride")
            ->assertStatus(409);
    }
}
