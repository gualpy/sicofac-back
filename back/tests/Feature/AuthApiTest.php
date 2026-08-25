<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_login_and_logout_with_sanctum_token(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Auth User',
            'email' => 'auth@example.com',
            'password' => 'Password123!',
            'device_name' => 'phpunit',
        ])->assertCreated();

        $token = $register->json('token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('email', 'auth@example.com');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'auth@example.com',
            'password' => 'Password123!',
            'device_name' => 'phpunit-login',
        ])->assertOk();

        $loginToken = $login->json('token');
        $this->assertNotEmpty($loginToken);

        $this->withHeader('Authorization', "Bearer {$loginToken}")
            ->deleteJson('/api/auth/logout')
            ->assertNoContent();
    }
}
