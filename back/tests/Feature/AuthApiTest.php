<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function starterPlanId(): int
    {
        return Plan::query()->where('slug', 'starter')->value('id');
    }

    public function test_user_can_register_confirm_email_login_and_logout(): void
    {
        Notification::fake();

        $register = $this->postJson('/api/auth/register', [
            'name' => 'Auth User',
            'email' => 'auth@example.com',
            'password' => 'Password123!',
            'device_name' => 'phpunit',
            'company_name' => 'Auth User Company',
            'company_ruc' => '0999999999001',
            'company_environment' => 'test',
            'terms_accepted' => true,
            'plan_id' => $this->starterPlanId(),
        ])->assertCreated();

        // No token yet -- the account exists but is pending confirmation.
        $this->assertArrayNotHasKey('token', $register->json());
        $this->assertNotEmpty($register->json('company.id'));

        $user = User::query()->where('email', 'auth@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->terms_accepted_at);
        $this->assertSame(config('legal.terms_version'), $user->terms_version);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.terms_accepted',
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);

        Notification::assertSentTo($user, VerifyEmail::class);

        // Confirming happens by visiting the signed link from the email.
        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->get($verifyUrl)->assertRedirect(config('app.frontend_url').'/email-confirmado?ok=1');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'auth@example.com',
            'password' => 'Password123!',
            'device_name' => 'phpunit-login',
        ])->assertOk();

        $loginToken = $login->json('token');
        $this->assertNotEmpty($loginToken);

        $this->withHeader('Authorization', "Bearer {$loginToken}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', 'auth@example.com');

        $this->withHeader('Authorization', "Bearer {$loginToken}")
            ->deleteJson('/api/auth/logout')
            ->assertNoContent();
    }

    public function test_login_is_blocked_before_email_is_confirmed(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Pending User',
            'email' => 'pending@example.com',
            'password' => 'Password123!',
            'company_name' => 'Pending Co',
            'company_ruc' => '0999999999002',
            'company_environment' => 'test',
            'terms_accepted' => true,
            'plan_id' => $this->starterPlanId(),
        ])->assertCreated();

        $this->postJson('/api/auth/login', [
            'email' => 'pending@example.com',
            'password' => 'Password123!',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_verification_link_with_wrong_hash_does_not_confirm_the_account(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Tampered User',
            'email' => 'tampered@example.com',
            'password' => 'Password123!',
            'company_name' => 'Tampered Co',
            'company_ruc' => '0999999999003',
            'company_environment' => 'test',
            'terms_accepted' => true,
            'plan_id' => $this->starterPlanId(),
        ])->assertCreated();

        $user = User::query()->where('email', 'tampered@example.com')->firstOrFail();

        $badUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('someone-else@example.com')],
        );

        $this->get($badUrl)->assertRedirect(config('app.frontend_url').'/email-confirmado?ok=0');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_verification_gives_the_same_generic_response_whether_or_not_the_email_exists(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Resend User',
            'email' => 'resend@example.com',
            'password' => 'Password123!',
            'company_name' => 'Resend Co',
            'company_ruc' => '0999999999004',
            'company_environment' => 'test',
            'terms_accepted' => true,
            'plan_id' => $this->starterPlanId(),
        ])->assertCreated();

        $existing = $this->postJson('/api/auth/email/resend', ['email' => 'resend@example.com'])
            ->assertOk();

        $unknown = $this->postJson('/api/auth/email/resend', ['email' => 'nobody@example.com'])
            ->assertOk();

        $this->assertSame($existing->json('message'), $unknown->json('message'));
    }

    public function test_registering_without_accepting_terms_is_rejected(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'No Terms',
            'email' => 'noterms@example.com',
            'password' => 'Password123!',
            'company_name' => 'No Terms Co',
            'company_ruc' => '0999999999005',
            'company_environment' => 'test',
            'plan_id' => $this->starterPlanId(),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms_accepted');

        $this->assertDatabaseMissing('users', ['email' => 'noterms@example.com']);
    }
}
