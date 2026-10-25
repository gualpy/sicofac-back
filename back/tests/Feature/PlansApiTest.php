<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlansApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_plans_endpoint_lists_only_self_serve_plans(): void
    {
        $response = $this->getJson('/api/plans')->assertOk();

        $slugs = collect($response->json('plans'))->pluck('slug');

        $this->assertTrue($slugs->contains('starter'));
        $this->assertTrue($slugs->contains('pro'));
        // Empresa is sales-assisted, not part of the self-serve picker.
        $this->assertFalse($slugs->contains('empresa'));
    }

    public function test_registering_without_a_plan_is_rejected(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'No Plan',
            'email' => 'noplan@example.com',
            'password' => 'Password123!',
            'company_name' => 'No Plan Co',
            'company_ruc' => '0999999999900',
            'terms_accepted' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_id');
    }

    public function test_registering_with_the_non_public_plan_is_rejected(): void
    {
        $empresaId = \App\Models\Plan::query()->where('slug', 'empresa')->value('id');

        $this->postJson('/api/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'Password123!',
            'company_name' => 'Sneaky Co',
            'company_ruc' => '0999999999901',
            'terms_accepted' => true,
            'plan_id' => $empresaId,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_id');
    }
}
