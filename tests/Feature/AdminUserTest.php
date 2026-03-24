<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $superAdminRole = Role::create(['name' => 'Super-Admin']);
        Role::create(['name' => 'Admin']);

        $this->superAdmin = User::factory()->create([
            'password' => Hash::make('Password1!'),
        ]);
        $this->superAdmin->assignRole('Super-Admin');
        $this->token = $this->superAdmin->createToken('test')->plainTextToken;
    }

    // ─── FUNCTIONAL: Admin Login ─────────────────────────────────────

    public function test_admin_can_login(): void
    {
        $response = $this->postJson('/api/users/login', [
            'email' => $this->superAdmin->email,
            'password' => 'Password1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['success', 'token', 'user'])
            ->assertJson(['success' => true]);
    }

    public function test_admin_login_fails_with_wrong_credentials(): void
    {
        $response = $this->postJson('/api/users/login', [
            'email' => $this->superAdmin->email,
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'Invalid credentials.']);
    }

    public function test_admin_login_fails_with_nonexistent_email(): void
    {
        $response = $this->postJson('/api/users/login', [
            'email' => 'nobody@example.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(401);
    }

    // ─── FUNCTIONAL: List Users ──────────────────────────────────────

    public function test_super_admin_can_list_users(): void
    {
        User::factory()->count(3)->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_non_super_admin_cannot_list_users(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/users');

        $response->assertStatus(403);
    }

    // ─── FUNCTIONAL: Show User ───────────────────────────────────────

    public function test_super_admin_can_show_user(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/users/{$user->id}");

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_show_returns_404_for_nonexistent_user(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/users/99999');

        $response->assertStatus(404);
    }

    // ─── FUNCTIONAL: Create User ─────────────────────────────────────

    public function test_super_admin_can_create_user(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/users', [
                'first_name' => 'New',
                'last_name' => 'User',
                'email' => 'new@example.com',
                'password' => 'Password1!',
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'User created successfully.']);

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_create_user_fails_with_duplicate_email(): void
    {
        $existing = User::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/users', [
                'first_name' => 'Dup',
                'last_name' => 'User',
                'email' => $existing->email,
                'password' => 'Password1!',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_create_user_fails_with_short_password(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/users', [
                'first_name' => 'Bad',
                'last_name' => 'Pass',
                'email' => 'badpass@example.com',
                'password' => 'short',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    // ─── FUNCTIONAL: Delete User ─────────────────────────────────────

    public function test_super_admin_can_delete_regular_user(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/users/{$user->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'User deleted successfully.']);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_cannot_delete_super_admin(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/users/{$this->superAdmin->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Super-Admin cannot be deleted.']);
    }

    // ─── FUNCTIONAL: Sync Roles ──────────────────────────────────────

    public function test_super_admin_can_sync_roles(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/users/{$user->id}/roles", [
                'roles' => ['Admin'],
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'User roles updated successfully.']);

        $this->assertTrue($user->fresh()->hasRole('Admin'));
    }

    public function test_cannot_modify_super_admin_roles(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/users/{$this->superAdmin->id}/roles", [
                'roles' => ['Admin'],
            ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Super-Admin roles cannot be modified.']);
    }

    public function test_sync_roles_fails_with_nonexistent_role(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/users/{$user->id}/roles", [
                'roles' => ['NonExistentRole'],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('roles.0');
    }

    // ─── SECURITY: Unauthenticated Access ────────────────────────────

    public function test_unauthenticated_cannot_access_admin_routes(): void
    {
        $this->getJson('/api/users')->assertStatus(401);
        $this->postJson('/api/users')->assertStatus(401);
        $this->deleteJson('/api/users/1')->assertStatus(401);
    }
}
