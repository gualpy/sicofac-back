<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_company_access_is_blocked(): void
    {
        $userA = User::query()->create([
            'name' => 'User A',
            'email' => 'a@example.com',
            'password' => 'password',
        ]);

        $companyA = Company::query()->create([
            'name' => 'Company A',
            'ruc' => '0999999999001',
            'environment' => 'test',
        ]);

        $companyB = Company::query()->create([
            'name' => 'Company B',
            'ruc' => '0999999999002',
            'environment' => 'test',
        ]);

        $userA->companies()->attach($companyA->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        $customerB = Customer::query()->create([
            'company_id' => $companyB->id,
            'name' => 'Cliente B',
            'identification_type' => '05',
            'identification_number' => '0922222222',
        ]);

        Sanctum::actingAs($userA);

        $this->getJson("/api/companies/{$companyA->id}/customers/{$customerB->id}")
            ->assertNotFound();
    }

    public function test_issue_permission_blocks_seller_role(): void
    {
        $seller = User::query()->create([
            'name' => 'Seller',
            'email' => 'seller@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Company Seller',
            'ruc' => '0999999999003',
            'environment' => 'test',
        ]);

        $seller->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Seller->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($seller);

        $invoiceId = $this->postJson("/api/companies/{$company->id}/invoices", [
            'document_code' => '01',
            'items' => [
                [
                    'code' => 'SKU-S-1',
                    'name' => 'Servicio Seller',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'discount' => 0,
                    'tax_rate' => 15,
                ],
            ],
        ])->assertCreated()->json('id');

        $this->postJson("/api/companies/{$company->id}/invoices/{$invoiceId}/emit")
            ->assertForbidden();
    }

    public function test_update_and_delete_are_blocked_when_invoice_is_not_draft(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Company Draft',
            'ruc' => '0999999999004',
            'environment' => 'test',
        ]);

        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($owner);

        $invoiceId = $this->postJson("/api/companies/{$company->id}/invoices", [
            'document_code' => '01',
            'items' => [
                [
                    'code' => 'SKU-D-1',
                    'name' => 'Servicio D',
                    'quantity' => 1,
                    'unit_price' => 10,
                    'discount' => 0,
                    'tax_rate' => 15,
                ],
            ],
        ])->assertCreated()->json('id');

        $invoice = Invoice::query()->findOrFail($invoiceId);
        $invoice->update(['status' => InvoiceStatus::Authorized]);

        $this->patchJson("/api/companies/{$company->id}/invoices/{$invoiceId}", [
            'items' => [
                [
                    'code' => 'SKU-D-2',
                    'name' => 'Servicio D2',
                    'quantity' => 1,
                    'unit_price' => 20,
                    'discount' => 0,
                    'tax_rate' => 15,
                ],
            ],
        ])->assertForbidden();

        $this->deleteJson("/api/companies/{$company->id}/invoices/{$invoiceId}")
            ->assertForbidden();
    }

    public function test_owner_only_company_critical_config(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner Cfg',
            'email' => 'ownercfg@example.com',
            'password' => 'password',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin Cfg',
            'email' => 'admincfg@example.com',
            'password' => 'password',
        ]);

        $company = Company::query()->create([
            'name' => 'Company Cfg',
            'ruc' => '0999999999005',
            'environment' => 'test',
        ]);

        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);
        $admin->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Admin->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($admin);
        $this->patchJson("/api/companies/{$company->id}/environment", [
            'environment' => 'production',
        ])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/companies/{$company->id}/environment", [
            'environment' => 'production',
        ])->assertOk()->assertJsonPath('environment', 'production');
    }

    public function test_invoice_delete_is_soft_delete(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner Soft',
            'email' => 'ownersoft@example.com',
            'password' => 'password',
        ]);
        $company = Company::query()->create([
            'name' => 'Company Soft',
            'ruc' => '0999999999006',
            'environment' => 'test',
        ]);
        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($owner);
        $invoiceId = $this->postJson("/api/companies/{$company->id}/invoices", [
            'document_code' => '01',
            'items' => [[
                'code' => 'SKU-SD-1',
                'name' => 'Soft',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 15,
            ]],
        ])->assertCreated()->json('id');

        $this->deleteJson("/api/companies/{$company->id}/invoices/{$invoiceId}")
            ->assertNoContent();

        $this->assertSoftDeleted('invoices', ['id' => $invoiceId]);
    }

    public function test_cannot_delete_customer_or_product_if_referenced(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner Ref',
            'email' => 'ownerref@example.com',
            'password' => 'password',
        ]);
        $company = Company::query()->create([
            'name' => 'Company Ref',
            'ruc' => '0999999999007',
            'environment' => 'test',
        ]);
        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($owner);

        $customerId = $this->postJson("/api/companies/{$company->id}/customers", [
            'name' => 'Cliente Ref',
            'identification_type' => '05',
            'identification_number' => '0991231231',
        ])->assertCreated()->json('id');

        $productId = $this->postJson("/api/companies/{$company->id}/products", [
            'code' => 'SKU-REF-1',
            'name' => 'Producto Ref',
            'unit_price' => 10,
            'tax_rate' => 15,
        ])->assertCreated()->json('id');

        $this->postJson("/api/companies/{$company->id}/invoices", [
            'customer_id' => $customerId,
            'items' => [[
                'product_id' => $productId,
                'code' => 'SKU-REF-1',
                'name' => 'Producto Ref',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 15,
            ]],
        ])->assertCreated();

        $this->deleteJson("/api/companies/{$company->id}/customers/{$customerId}")
            ->assertForbidden();

        $this->deleteJson("/api/companies/{$company->id}/products/{$productId}")
            ->assertForbidden();
    }

    public function test_issue_double_submit_is_blocked_after_first_transition(): void
    {
        Bus::fake();

        $owner = User::query()->create([
            'name' => 'Owner Lock',
            'email' => 'ownerlock@example.com',
            'password' => 'password',
        ]);
        $company = Company::query()->create([
            'name' => 'Company Lock',
            'ruc' => '0999999999008',
            'environment' => 'test',
        ]);
        $owner->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Owner->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($owner);

        $invoiceId = $this->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [[
                'code' => 'SKU-L-1',
                'name' => 'Lock',
                'quantity' => 1,
                'unit_price' => 10,
                'tax_rate' => 15,
            ]],
        ])->assertCreated()->json('id');

        $this->postJson("/api/companies/{$company->id}/invoices/{$invoiceId}/emit")
            ->assertOk();

        $this->postJson("/api/companies/{$company->id}/invoices/{$invoiceId}/emit")
            ->assertStatus(409);

        $this->assertSame(
            InvoiceStatus::Processing,
            Invoice::query()->findOrFail($invoiceId)->status
        );
    }
}
