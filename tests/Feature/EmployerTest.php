<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Notifications\NewEmployerRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployerTest extends TestCase
{
    use RefreshDatabase;

    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->validPayload = [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'company_name' => 'TechCorp Inc',
            'email' => 'jane@gmail.com',
            'contact' => '0551234567',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'business_type' => 'Startup',
        ];
    }

    // ─── FUNCTIONAL: Registration ────────────────────────────────────

    public function test_employer_can_register(): void
    {
        $response = $this->postJson('/api/employers/register', $this->validPayload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Company registered successfully. Your account will be reviewed and activated by ForgeKin.',
            ]);

        $this->assertDatabaseHas('employers', [
            'email' => 'jane@gmail.com',
            'verification_status' => 'inactive',
        ]);
    }

    public function test_employer_registration_sends_admin_notification(): void
    {
        $this->postJson('/api/employers/register', $this->validPayload);

        Notification::assertSentOnDemand(NewEmployerRegistered::class);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        Employer::factory()->create(['email' => 'jane@gmail.com']);

        $response = $this->postJson('/api/employers/register', $this->validPayload);

        $response->assertStatus(409)
            ->assertJson(['message' => 'A Company with this email already exists.']);
    }

    public function test_registration_fails_with_duplicate_company_name(): void
    {
        Employer::factory()->create(['company_name' => 'TechCorp Inc']);

        $response = $this->postJson('/api/employers/register', $this->validPayload);

        $response->assertStatus(409)
            ->assertJson(['message' => 'A Company with this company name already exists.']);
    }

    public function test_registration_fails_with_invalid_business_type(): void
    {
        $this->validPayload['business_type'] = 'InvalidType';
        $response = $this->postJson('/api/employers/register', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('business_type');
    }

    public function test_registration_fails_without_company_name(): void
    {
        unset($this->validPayload['company_name']);
        $response = $this->postJson('/api/employers/register', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('company_name');
    }

    public function test_registration_fails_without_password_confirmation(): void
    {
        unset($this->validPayload['password_confirmation']);
        $response = $this->postJson('/api/employers/register', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // ─── FUNCTIONAL: Login ───────────────────────────────────────────

    public function test_active_employer_can_login(): void
    {
        $employer = Employer::factory()->active()->create([
            'email' => 'active@gmail.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/employers/login', [
            'email' => 'active@gmail.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'token', 'data', 'success'])
            ->assertJson(['success' => true]);
    }

    public function test_inactive_employer_cannot_login(): void
    {
        $employer = Employer::factory()->create([
            'email' => 'inactive@gmail.com',
            'password' => Hash::make('Password1!'),
            'verification_status' => 'inactive',
        ]);

        $response = $this->postJson('/api/employers/login', [
            'email' => 'inactive@gmail.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(403)
            ->assertJson(['requires_verification' => true]);
    }

    public function test_employer_login_fails_with_wrong_password(): void
    {
        $employer = Employer::factory()->active()->create([
            'email' => 'employer@gmail.com',
            'password' => Hash::make('Password1!'),
        ]);

        $response = $this->postJson('/api/employers/login', [
            'email' => 'employer@gmail.com',
            'password' => 'WrongPass123!',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    // ─── FUNCTIONAL: Index / List ────────────────────────────────────

    public function test_can_list_employers(): void
    {
        Employer::factory()->count(3)->create();

        $response = $this->getJson('/api/employers');

        $response->assertStatus(200)->assertJsonStructure(['data']);
    }

    public function test_employer_search_filters_results(): void
    {
        Employer::factory()->create(['company_name' => 'UniqueCompanyXYZ']);
        Employer::factory()->count(5)->create();

        $response = $this->getJson('/api/employers?search=UniqueCompanyXYZ');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
    }

    // ─── FUNCTIONAL: Show ────────────────────────────────────────────

    public function test_can_show_employer(): void
    {
        $employer = Employer::factory()->create();

        $response = $this->getJson("/api/employers/{$employer->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_show_returns_404_for_nonexistent_employer(): void
    {
        $response = $this->getJson('/api/employers/99999');
        $response->assertStatus(404);
    }

    // ─── FUNCTIONAL: Update ──────────────────────────────────────────

    public function test_employer_can_update_own_profile(): void
    {
        $employer = Employer::factory()->active()->create();
        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/employers/{$employer->id}", [
                'company_name' => 'Updated Corp',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Employer updated successfully.']);
    }

    public function test_employer_cannot_update_another_employer(): void
    {
        $employer1 = Employer::factory()->active()->create();
        $employer2 = Employer::factory()->active()->create();
        $token = $employer1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/employers/{$employer2->id}", [
                'company_name' => 'Stolen Name',
            ]);

        $response->assertStatus(403);
    }

    // ─── FUNCTIONAL: Destroy ─────────────────────────────────────────

    public function test_employer_can_delete_own_account(): void
    {
        $employer = Employer::factory()->active()->create();
        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/employers/{$employer->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Employer deleted successfully.']);

        $this->assertDatabaseMissing('employers', ['id' => $employer->id]);
    }

    public function test_employer_cannot_delete_another_employer(): void
    {
        $employer1 = Employer::factory()->active()->create();
        $employer2 = Employer::factory()->active()->create();
        $token = $employer1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/employers/{$employer2->id}");

        $response->assertStatus(403);
    }

    // ─── FUNCTIONAL: Logout ──────────────────────────────────────────

    public function test_employer_can_logout(): void
    {
        $employer = Employer::factory()->active()->create();
        $token = $employer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/employers/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'You have been logged out successfully.']);
    }

    public function test_unauthenticated_user_cannot_access_protected_employer_routes(): void
    {
        $employer = Employer::factory()->create();

        $this->putJson("/api/employers/{$employer->id}")->assertStatus(401);
        $this->deleteJson("/api/employers/{$employer->id}")->assertStatus(401);
        $this->postJson('/api/employers/logout')->assertStatus(401);
    }
}
