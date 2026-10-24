<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\AdminMagicLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $ruc, ?string $planSlug = 'starter'): Company
    {
        $planId = $planSlug ? Plan::query()->where('slug', $planSlug)->value('id') : null;

        return Company::query()->create([
            'name' => "Co {$ruc}",
            'ruc' => $ruc,
            'environment' => 'test',
            'sri_signing_enabled' => false,
            'sri_submission_enabled' => false,
            'plan_id' => $planId,
        ]);
    }

    private function admin(): User
    {
        // is_super_admin is deliberately not mass-assignable (no self-serve
        // way to become an admin) -- forceFill it directly, same pattern as
        // terms_accepted_at.
        $user = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user->fresh();
    }

    public function test_regular_user_cannot_reach_admin_routes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/companies')->assertStatus(403);
    }

    public function test_super_admin_lists_companies_with_plan_and_owner(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $company = $this->makeCompany('0999999999001');
        $owner->companies()->attach($company->id, ['role' => 'owner', 'status' => CompanyMembershipStatus::Active->value]);

        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/companies')->assertOk();
        $row = collect($response->json('data'))->firstWhere('id', $company->id);

        $this->assertSame('owner@example.com', $row['owner_email']);
        $this->assertSame('Starter', $row['plan']['name']);
        $this->assertTrue($row['is_active']);
        $this->assertSame(0, $row['documents_this_month']);
    }

    public function test_super_admin_can_activate_and_deactivate_a_company(): void
    {
        $company = $this->makeCompany('0999999999002');
        Sanctum::actingAs($this->admin());

        $this->postJson("/api/admin/companies/{$company->id}/deactivate")
            ->assertOk()
            ->assertJson(['is_active' => false]);
        $this->assertFalse($company->fresh()->is_active);

        $this->postJson("/api/admin/companies/{$company->id}/activate")
            ->assertOk()
            ->assertJson(['is_active' => true]);
        $this->assertTrue($company->fresh()->is_active);
    }

    public function test_a_deactivated_company_cannot_issue_invoices(): void
    {
        $owner = User::factory()->create();
        $company = $this->makeCompany('0999999999003');
        $owner->companies()->attach($company->id, ['role' => 'owner', 'status' => CompanyMembershipStatus::Active->value]);
        $company->update(['is_active' => false]);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/companies/{$company->id}/invoices", [
            'items' => [['code' => 'X', 'name' => 'Item', 'quantity' => 1, 'unit_price' => 10]],
        ]);

        $response->assertStatus(403);
        $this->assertSame('company_inactive', $response->json('error.code'));
    }

    public function test_super_admin_can_create_update_and_delete_a_plan(): void
    {
        Sanctum::actingAs($this->admin());

        $created = $this->postJson('/api/admin/plans', [
            'slug' => 'mid-tier',
            'name' => 'Mid Tier',
            'price_cents' => 6000,
            'monthly_document_limit' => 150,
            'max_establishments' => 2,
            'max_users' => 4,
            'is_public' => true,
            'sort_order' => 5,
        ])->assertCreated();

        $planId = $created->json('id');

        $this->putJson("/api/admin/plans/{$planId}", ['price_cents' => 6500])
            ->assertOk()
            ->assertJson(['price_cents' => 6500, 'name' => 'Mid Tier']);

        $this->deleteJson("/api/admin/plans/{$planId}")->assertStatus(204);
        $this->assertDatabaseMissing('plans', ['id' => $planId]);
    }

    public function test_deleting_a_plan_with_companies_on_it_is_blocked(): void
    {
        $starter = Plan::query()->where('slug', 'starter')->first();
        $this->makeCompany('0999999999004', 'starter');

        Sanctum::actingAs($this->admin());

        $this->deleteJson("/api/admin/plans/{$starter->id}")->assertStatus(409);
        $this->assertDatabaseHas('plans', ['id' => $starter->id]);
    }

    public function test_metrics_report_an_mrr_estimate_from_active_companies(): void
    {
        $this->makeCompany('0999999999005', 'starter'); // $45.00/year active
        $inactive = $this->makeCompany('0999999999006', 'pro'); // $89.00/year but will be deactivated
        $inactive->update(['is_active' => false]);

        Sanctum::actingAs($this->admin());

        $response = $this->getJson('/api/admin/metrics')->assertOk();

        // Only the active Starter company counts: 4500 cents / 12.
        $this->assertEqualsWithDelta(4500 / 12, $response->json('mrr_cents'), 0.01);
        $this->assertSame(1, $response->json('active_companies'));
    }

    public function test_magic_link_request_never_reveals_whether_the_email_is_a_super_admin(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $known = $this->postJson('/api/auth/admin/magic-link', ['email' => 'admin@example.com'])->assertOk();
        $unknown = $this->postJson('/api/auth/admin/magic-link', ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentTo($admin, AdminMagicLinkNotification::class);
    }

    public function test_magic_login_with_a_valid_signed_link_issues_a_token(): void
    {
        $admin = $this->admin();

        $url = URL::temporarySignedRoute('admin.magic-login', now()->addMinutes(15), ['id' => $admin->id, 'hash' => sha1($admin->email)]);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $response = $this->get($path);

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/login-callback?ok=1&token=', $response->headers->get('Location'));
    }

    public function test_magic_login_rejects_a_non_admin_user(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute('admin.magic-login', now()->addMinutes(15), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);

        $response = $this->get($path);

        $this->assertStringContainsString('ok=0', $response->headers->get('Location'));
    }
}
