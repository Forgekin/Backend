<?php

namespace Tests\Feature;

use App\Models\Freelancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FreelancerAuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── FUNCTIONAL: Login ───────────────────────────────────────────

    public function test_verified_freelancer_can_login(): void
    {
        $freelancer = Freelancer::factory()->verified()->create([
            'email' => 'test@gmail.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/freelancers/login', [
            'email' => 'test@gmail.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'token', 'data', 'success'])
            ->assertJson(['success' => true, 'message' => 'Login successful']);
    }

    public function test_login_returns_sanctum_token(): void
    {
        $freelancer = Freelancer::factory()->verified()->create([
            'email' => 'token-test@gmail.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/freelancers/login', [
            'email' => 'token-test@gmail.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $freelancer = Freelancer::factory()->verified()->create([
            'email' => 'wrong-pass@gmail.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/freelancers/login', [
            'email' => 'wrong-pass@gmail.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'The provided credentials are incorrect',
                'success' => false,
            ]);
    }

    public function test_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/freelancers/login', [
            'email' => 'nobody@gmail.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_unverified_freelancer_cannot_login(): void
    {
        $freelancer = Freelancer::factory()->create([
            'email' => 'unverified@gmail.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/freelancers/login', [
            'email' => 'unverified@gmail.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'requires_verification' => true,
                'success' => false,
            ]);
    }

    public function test_login_revokes_previous_tokens(): void
    {
        $freelancer = Freelancer::factory()->verified()->create([
            'email' => 'revoke-test@gmail.com',
            'password' => Hash::make('Password1!'),
        ]);

        // Login twice
        $this->postJson('/api/freelancers/login', [
            'email' => 'revoke-test@gmail.com',
            'password' => 'Password1!',
        ]);

        $this->postJson('/api/freelancers/login', [
            'email' => 'revoke-test@gmail.com',
            'password' => 'Password1!',
        ]);

        // Only 1 token should exist (previous revoked)
        $this->assertEquals(1, $freelancer->tokens()->count());
    }

    // ─── VALIDATION: Login Input ─────────────────────────────────────

    public function test_login_fails_without_email(): void
    {
        $response = $this->postJson('/api/freelancers/login', [
            'password' => 'Password1!',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_fails_without_password(): void
    {
        $response = $this->postJson('/api/freelancers/login', [
            'email' => 'test@gmail.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // ─── FUNCTIONAL: Logout ──────────────────────────────────────────

    public function test_authenticated_freelancer_can_logout(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/freelancers/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Successfully logged out']);

        $this->assertEquals(0, $freelancer->tokens()->count());
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/freelancers/logout');
        $response->assertStatus(401);
    }
}
