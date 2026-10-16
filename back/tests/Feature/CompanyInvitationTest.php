<?php

namespace Tests\Feature;

use App\Enums\CompanyMembershipRole;
use App\Enums\CompanyMembershipStatus;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\CompanyInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompanyInvitationTest extends TestCase
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
            'email_verified_at' => now(),
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

    public function test_owner_can_invite_a_brand_new_user_who_accepts_and_logs_in(): void
    {
        Notification::fake();

        [$company, $owner] = $this->makeCompanyWithOwner('Acme', '0999999999701', 'owner1@example.com');

        Sanctum::actingAs($owner);

        $this->postJson("/api/companies/{$company->id}/invitations", [
            'email' => 'juan@example.com',
            'role' => CompanyMembershipRole::Seller->value,
        ])->assertCreated();

        $invitation = CompanyInvitation::query()->where('email', 'juan@example.com')->firstOrFail();
        Notification::assertSentOnDemand(CompanyInvitationNotification::class);

        // Public lookup tells the frontend this is a brand new user.
        $this->getJson("/api/auth/invitations/{$invitation->token}")
            ->assertOk()
            ->assertJsonPath('requires_password', true)
            ->assertJsonPath('company_name', 'Acme');

        $this->postJson("/api/auth/invitations/{$invitation->token}/accept", [
            'name' => 'Juan',
            'password' => 'Password123!',
        ])->assertOk();

        $juan = User::query()->where('email', 'juan@example.com')->firstOrFail();
        $this->assertNotNull($juan->email_verified_at);
        $this->assertTrue($juan->hasCompanyRole($company->id, [CompanyMembershipRole::Seller->value]));

        // Accepted invitation can't be reused.
        $this->postJson("/api/auth/invitations/{$invitation->token}/accept", [
            'name' => 'Juan',
            'password' => 'Password123!',
        ])->assertNotFound();

        // Juan can now log in normally.
        $this->postJson('/api/auth/login', [
            'email' => 'juan@example.com',
            'password' => 'Password123!',
        ])->assertOk();
    }

    public function test_existing_user_accepting_an_invitation_is_just_attached_without_a_password(): void
    {
        Notification::fake();

        [$companyA] = $this->makeCompanyWithOwner('Company A', '0999999999702', 'ownerA2@example.com');
        [$companyB, $ownerB] = $this->makeCompanyWithOwner('Company B', '0999999999703', 'ownerB2@example.com');

        Sanctum::actingAs($ownerB);
        $this->postJson("/api/companies/{$companyB->id}/invitations", [
            'email' => 'ownerA2@example.com',
            'role' => CompanyMembershipRole::Admin->value,
        ])->assertCreated();

        $invitation = CompanyInvitation::query()->where('email', 'ownerA2@example.com')->firstOrFail();

        $this->getJson("/api/auth/invitations/{$invitation->token}")
            ->assertOk()
            ->assertJsonPath('requires_password', false);

        $this->postJson("/api/auth/invitations/{$invitation->token}/accept")->assertOk();

        $ownerA = User::query()->where('email', 'ownerA2@example.com')->firstOrFail();
        $this->assertTrue($ownerA->belongsToCompany($companyA->id));
        $this->assertTrue($ownerA->hasCompanyRole($companyB->id, [CompanyMembershipRole::Admin->value]));
    }

    public function test_non_owner_cannot_invite_members(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Acme', '0999999999704', 'owner3@example.com');

        $seller = User::query()->create([
            'name' => 'Seller',
            'email' => 'seller3@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);
        $seller->companies()->attach($company->id, [
            'role' => CompanyMembershipRole::Seller->value,
            'status' => CompanyMembershipStatus::Active->value,
        ]);

        Sanctum::actingAs($seller);

        $this->postJson("/api/companies/{$company->id}/invitations", [
            'email' => 'nope@example.com',
            'role' => CompanyMembershipRole::Seller->value,
        ])->assertForbidden();
    }

    public function test_owner_cannot_invite_into_a_company_they_do_not_belong_to(): void
    {
        [$companyA, $ownerA] = $this->makeCompanyWithOwner('Company A', '0999999999705', 'ownerA4@example.com');
        [$companyB] = $this->makeCompanyWithOwner('Company B', '0999999999706', 'ownerB4@example.com');

        Sanctum::actingAs($ownerA);

        $this->postJson("/api/companies/{$companyB->id}/invitations", [
            'email' => 'nope@example.com',
            'role' => CompanyMembershipRole::Seller->value,
        ])->assertForbidden();
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Acme', '0999999999707', 'owner5@example.com');

        $invitation = CompanyInvitation::query()->create([
            'company_id' => $company->id,
            'invited_by' => $owner->id,
            'email' => 'late@example.com',
            'role' => CompanyMembershipRole::Seller->value,
            'token' => 'expired-token',
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson("/api/auth/invitations/{$invitation->token}")->assertNotFound();

        $this->postJson("/api/auth/invitations/{$invitation->token}/accept", [
            'name' => 'Late',
            'password' => 'Password123!',
        ])->assertNotFound();

        $this->assertNull(User::query()->where('email', 'late@example.com')->first());
    }

    public function test_revoked_invitation_cannot_be_accepted(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Acme', '0999999999708', 'owner6@example.com');

        Sanctum::actingAs($owner);
        $this->postJson("/api/companies/{$company->id}/invitations", [
            'email' => 'revoked@example.com',
            'role' => CompanyMembershipRole::Seller->value,
        ])->assertCreated();

        $invitation = CompanyInvitation::query()->where('email', 'revoked@example.com')->firstOrFail();

        $this->deleteJson("/api/companies/{$company->id}/invitations/{$invitation->id}")->assertNoContent();

        $this->postJson("/api/auth/invitations/{$invitation->token}/accept", [
            'name' => 'Revoked',
            'password' => 'Password123!',
        ])->assertNotFound();
    }

    public function test_cannot_invite_an_email_that_is_already_an_active_member(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner('Acme', '0999999999709', 'owner7@example.com');

        Sanctum::actingAs($owner);

        $this->postJson("/api/companies/{$company->id}/invitations", [
            'email' => 'owner7@example.com',
            'role' => CompanyMembershipRole::Seller->value,
        ])->assertStatus(422);
    }

    public function test_list_members_includes_active_members_and_pending_invitations(): void
    {
        Notification::fake();

        [$company, $owner] = $this->makeCompanyWithOwner('Acme', '0999999999710', 'owner8@example.com');

        Sanctum::actingAs($owner);
        $this->postJson("/api/companies/{$company->id}/invitations", [
            'email' => 'pending8@example.com',
            'role' => CompanyMembershipRole::Seller->value,
        ])->assertCreated();

        $response = $this->getJson("/api/companies/{$company->id}/members")->assertOk();

        $this->assertCount(1, $response->json('members'));
        $this->assertCount(1, $response->json('pending_invitations'));
        $this->assertSame('pending8@example.com', $response->json('pending_invitations.0.email'));
    }
}
