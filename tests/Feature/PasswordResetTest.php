<?php

namespace Tests\Feature;

use App\Mail\FreelancerPasswordResetMail;
use App\Models\Freelancer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    // ─── FUNCTIONAL: Forgot Password ─────────────────────────────────

    public function test_forgot_password_sends_reset_email(): void
    {
        Mail::fake();
        $freelancer = Freelancer::factory()->verified()->create(['email' => 'test@example.com']);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Reset link sent']);

        Mail::assertQueued(FreelancerPasswordResetMail::class);
    }

    public function test_forgot_password_creates_reset_token(): void
    {
        Mail::fake();
        $freelancer = Freelancer::factory()->verified()->create(['email' => 'test@example.com']);

        $this->postJson('/api/forgot-password', ['email' => 'test@example.com']);

        $this->assertDatabaseHas('freelancer_password_resets', [
            'email' => 'test@example.com',
        ]);
    }

    public function test_forgot_password_deletes_old_tokens(): void
    {
        Mail::fake();
        $freelancer = Freelancer::factory()->verified()->create(['email' => 'test@example.com']);

        // Create old token
        DB::table('freelancer_password_resets')->insert([
            'email' => 'test@example.com',
            'token' => 'old-token',
            'created_at' => now(),
        ]);

        $this->postJson('/api/forgot-password', ['email' => 'test@example.com']);

        // Only 1 token should exist (old one deleted)
        $count = DB::table('freelancer_password_resets')
            ->where('email', 'test@example.com')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_forgot_password_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ─── FUNCTIONAL: Reset Password ──────────────────────────────────

    public function test_can_reset_password_with_valid_token(): void
    {
        $freelancer = Freelancer::factory()->verified()->create([
            'email' => 'test@example.com',
        ]);

        $plainToken = 'valid-reset-token-1234567890';
        DB::table('freelancer_password_resets')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'token' => $plainToken,
            'email' => 'test@example.com',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Password updated successfully']);

        // Verify password changed
        $freelancer->refresh();
        $this->assertTrue(Hash::check('NewPassword1!', $freelancer->password));

        // Verify token deleted
        $this->assertDatabaseMissing('freelancer_password_resets', [
            'email' => 'test@example.com',
        ]);
    }

    public function test_reset_fails_with_invalid_token(): void
    {
        $freelancer = Freelancer::factory()->verified()->create(['email' => 'test@example.com']);

        DB::table('freelancer_password_resets')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make('real-token'),
            'created_at' => now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'token' => 'wrong-token',
            'email' => 'test@example.com',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired token']);
    }

    public function test_reset_fails_with_expired_token(): void
    {
        $freelancer = Freelancer::factory()->verified()->create(['email' => 'test@example.com']);

        $plainToken = 'expired-token-1234567890';
        DB::table('freelancer_password_resets')->insert([
            'email' => 'test@example.com',
            'token' => Hash::make($plainToken),
            'created_at' => Carbon::now()->subHours(2), // 2 hours ago, expired
        ]);

        $response = $this->postJson('/api/reset-password', [
            'token' => $plainToken,
            'email' => 'test@example.com',
            'password' => 'NewPassword1!',
            'password_confirmation' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Token expired']);
    }

    public function test_reset_fails_without_password_confirmation(): void
    {
        $response = $this->postJson('/api/reset-password', [
            'token' => 'some-token',
            'email' => 'test@example.com',
            'password' => 'NewPassword1!',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_reset_fails_with_short_password(): void
    {
        $response = $this->postJson('/api/reset-password', [
            'token' => 'some-token',
            'email' => 'test@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
