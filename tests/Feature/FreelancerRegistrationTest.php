<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\Freelancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FreelancerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->validPayload = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'other_names' => 'Middle',
            'email' => 'john@example.com',
            'contact' => '0551234567',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'gender' => 'male',
            'dob' => '2000-01-15',
        ];
    }

    // ─── FUNCTIONAL: Registration ────────────────────────────────────

    public function test_freelancer_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/freelancers', $this->validPayload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'first_name', 'last_name'],
            ]);

        $this->assertDatabaseHas('freelancers', [
            'email' => 'john@example.com',
            'first_name' => 'John',
        ]);
    }

    public function test_registration_sends_verification_email(): void
    {
        $this->postJson('/api/freelancers', $this->validPayload);

        Mail::assertQueued(VerificationCodeMail::class, function ($mail) {
            return true;
        });
    }

    public function test_registration_stores_hashed_password(): void
    {
        $this->postJson('/api/freelancers', $this->validPayload);

        $freelancer = Freelancer::where('email', 'john@example.com')->first();
        $this->assertNotEquals('Password1!', $freelancer->password);
        $this->assertTrue(\Hash::check('Password1!', $freelancer->password));
    }

    public function test_registration_generates_verification_code(): void
    {
        $this->postJson('/api/freelancers', $this->validPayload);

        $freelancer = Freelancer::where('email', 'john@example.com')->first();
        $this->assertNotNull($freelancer->verification_code);
        $this->assertEquals(6, strlen($freelancer->verification_code));
        $this->assertNotNull($freelancer->verification_code_expires_at);
    }

    // ─── VALIDATION: Required Fields ─────────────────────────────────

    public function test_registration_fails_without_first_name(): void
    {
        unset($this->validPayload['first_name']);
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('first_name');
    }

    public function test_registration_fails_without_last_name(): void
    {
        unset($this->validPayload['last_name']);
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('last_name');
    }

    public function test_registration_fails_without_email(): void
    {
        unset($this->validPayload['email']);
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_with_invalid_email(): void
    {
        $this->validPayload['email'] = 'not-an-email';
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        Freelancer::factory()->create(['email' => 'john@example.com']);

        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_fails_without_password(): void
    {
        unset($this->validPayload['password'], $this->validPayload['password_confirmation']);
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_short_password(): void
    {
        $this->validPayload['password'] = 'short';
        $this->validPayload['password_confirmation'] = 'short';
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_without_password_confirmation(): void
    {
        unset($this->validPayload['password_confirmation']);
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_mismatched_password(): void
    {
        $this->validPayload['password_confirmation'] = 'DifferentPass1!';
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_fails_with_invalid_gender(): void
    {
        $this->validPayload['gender'] = 'unknown';
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('gender');
    }

    public function test_registration_fails_with_underage_dob(): void
    {
        $this->validPayload['dob'] = now()->subYears(17)->format('Y-m-d');
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('dob');
    }

    public function test_registration_fails_without_contact(): void
    {
        unset($this->validPayload['contact']);
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('contact');
    }

    // ─── EDGE: Other names is optional ───────────────────────────────

    public function test_registration_succeeds_without_other_names(): void
    {
        unset($this->validPayload['other_names']);
        $response = $this->postJson('/api/freelancers', $this->validPayload);
        $response->assertStatus(201);
    }
}
