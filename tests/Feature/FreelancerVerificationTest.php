<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\Freelancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FreelancerVerificationTest extends TestCase
{
    use RefreshDatabase;

    // ─── FUNCTIONAL: Email Verification ──────────────────────────────

    public function test_freelancer_can_verify_email_with_valid_code(): void
    {
        $freelancer = Freelancer::factory()->create([
            'verification_code' => 'ABC123',
            'verification_code_expires_at' => now()->addMinutes(30),
        ]);

        $response = $this->postJson('/api/freelancers/verify-email', [
            'email' => $freelancer->email,
            'code' => 'ABC123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Email verified successfully']);

        $freelancer->refresh();
        $this->assertNotNull($freelancer->email_verified_at);
        $this->assertNull($freelancer->verification_code);
    }

    public function test_verification_fails_with_wrong_code(): void
    {
        $freelancer = Freelancer::factory()->create([
            'verification_code' => 'ABC123',
            'verification_code_expires_at' => now()->addMinutes(30),
        ]);

        $response = $this->postJson('/api/freelancers/verify-email', [
            'email' => $freelancer->email,
            'code' => 'WRONG1',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired verification code']);
    }

    public function test_verification_fails_with_expired_code(): void
    {
        $freelancer = Freelancer::factory()->create([
            'verification_code' => 'ABC123',
            'verification_code_expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->postJson('/api/freelancers/verify-email', [
            'email' => $freelancer->email,
            'code' => 'ABC123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Invalid or expired verification code']);
    }

    public function test_verification_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/freelancers/verify-email', [
            'email' => 'nonexistent@example.com',
            'code' => 'ABC123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_verification_fails_with_code_wrong_length(): void
    {
        $freelancer = Freelancer::factory()->create();

        $response = $this->postJson('/api/freelancers/verify-email', [
            'email' => $freelancer->email,
            'code' => 'AB',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('code');
    }

    // ─── FUNCTIONAL: Resend Verification ─────────────────────────────

    public function test_resend_verification_code_sends_new_email(): void
    {
        Mail::fake();
        $freelancer = Freelancer::factory()->create();

        $response = $this->postJson('/api/freelancers/resend-verification', [
            'email' => $freelancer->email,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'New verification code sent']);

        Mail::assertQueued(VerificationCodeMail::class);

        $freelancer->refresh();
        $this->assertNotNull($freelancer->verification_code);
    }

    public function test_resend_verification_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/freelancers/resend-verification', [
            'email' => 'fake@example.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
