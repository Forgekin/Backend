<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\User;
use App\Notifications\EmployerApproved;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminEmployerVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        Permission::create(['name' => 'employers.verify']);

        $role = Role::create(['name' => 'Super-Admin']);
        $role->givePermissionTo('employers.verify');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super-Admin');
        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    public function test_admin_can_approve_inactive_employer(): void
    {
        $employer = Employer::factory()->create(['verification_status' => 'inactive']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/employers/{$employer->id}/approve");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Employer approved successfully.']);

        $this->assertDatabaseHas('employers', [
            'id' => $employer->id,
            'verification_status' => 'active',
        ]);
    }

    public function test_approving_sends_notification_to_employer(): void
    {
        $employer = Employer::factory()->create(['verification_status' => 'inactive']);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/employers/{$employer->id}/approve");

        Notification::assertSentTo($employer, EmployerApproved::class);
    }

    public function test_approve_is_idempotent_when_already_active(): void
    {
        $employer = Employer::factory()->active()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/employers/{$employer->id}/approve");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Employer is already active.']);

        Notification::assertNothingSentTo($employer);
    }

    public function test_admin_can_revoke_verification(): void
    {
        $employer = Employer::factory()->active()->create();
        $employer->createToken('employer-session');

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/employers/{$employer->id}/revoke");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Employer verification revoked.']);

        $this->assertDatabaseHas('employers', [
            'id' => $employer->id,
            'verification_status' => 'inactive',
        ]);
        $this->assertCount(0, $employer->fresh()->tokens);
    }

    public function test_unauthenticated_cannot_approve(): void
    {
        $employer = Employer::factory()->create();

        $this->patchJson("/api/admin/employers/{$employer->id}/approve")
            ->assertStatus(401);
    }

    public function test_user_without_permission_cannot_approve(): void
    {
        Role::create(['name' => 'Plain']);
        $user = User::factory()->create();
        $user->assignRole('Plain');
        $token = $user->createToken('test')->plainTextToken;

        $employer = Employer::factory()->create();

        $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/admin/employers/{$employer->id}/approve")
            ->assertStatus(403);
    }

    public function test_approve_returns_404_for_nonexistent_employer(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson('/api/admin/employers/99999/approve')
            ->assertStatus(404);
    }
}
