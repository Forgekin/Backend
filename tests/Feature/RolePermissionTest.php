<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Super-Admin']);
        Permission::create(['name' => 'jobs.read']);
        Permission::create(['name' => 'jobs.create']);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('Super-Admin');
        $this->token = $this->superAdmin->createToken('test')->plainTextToken;
    }

    // ─── FUNCTIONAL: Roles CRUD ──────────────────────────────────────

    public function test_can_list_roles(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/roles');

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_can_create_role(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/roles', ['name' => 'Editor']);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Role created successfully.']);

        $this->assertDatabaseHas('roles', ['name' => 'Editor']);
    }

    public function test_create_role_fails_with_duplicate_name(): void
    {
        Role::create(['name' => 'Editor']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/roles', ['name' => 'Editor']);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_can_update_role(): void
    {
        $role = Role::create(['name' => 'OldName']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/roles/{$role->id}", ['name' => 'NewName']);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Role updated successfully.']);
    }

    public function test_cannot_update_super_admin_role(): void
    {
        $role = Role::where('name', 'Super-Admin')->first();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/roles/{$role->id}", ['name' => 'RenamedAdmin']);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Super-Admin role cannot be modified.']);
    }

    public function test_can_delete_role(): void
    {
        $role = Role::create(['name' => 'Disposable']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Role deleted successfully.']);
    }

    public function test_cannot_delete_super_admin_role(): void
    {
        $role = Role::where('name', 'Super-Admin')->first();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(403)
            ->assertJson(['message' => 'Super-Admin role cannot be deleted.']);
    }

    // ─── FUNCTIONAL: Permissions ─────────────────────────────────────

    public function test_can_list_permissions(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/permissions');

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_can_create_permission(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/permissions', ['name' => 'reports.view']);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Permission created successfully.']);
    }

    public function test_create_permission_fails_with_duplicate(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/permissions', ['name' => 'jobs.read']);

        $response->assertStatus(422)->assertJsonValidationErrors('name');
    }

    // ─── FUNCTIONAL: Sync Role Permissions ───────────────────────────

    public function test_can_sync_permissions_to_role(): void
    {
        $role = Role::create(['name' => 'Editor']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/roles/{$role->id}/permissions", [
                'permissions' => ['jobs.read', 'jobs.create'],
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Permissions synced successfully.']);

        $this->assertTrue($role->fresh()->hasPermissionTo('jobs.read'));
        $this->assertTrue($role->fresh()->hasPermissionTo('jobs.create'));
    }

    public function test_cannot_sync_permissions_to_super_admin(): void
    {
        $role = Role::where('name', 'Super-Admin')->first();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/roles/{$role->id}/permissions", [
                'permissions' => ['jobs.read'],
            ]);

        $response->assertStatus(403);
    }

    // ─── SECURITY: Non-Super-Admin Cannot Access ─────────────────────

    public function test_regular_user_cannot_access_role_routes(): void
    {
        $regularRole = Role::create(['name' => 'Regular']);
        $user = User::factory()->create();
        $user->assignRole('Regular');
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/roles')->assertStatus(403);

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/roles', ['name' => 'Hack'])->assertStatus(403);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/permissions')->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_role_routes(): void
    {
        $this->getJson('/api/roles')->assertStatus(401);
        $this->postJson('/api/roles')->assertStatus(401);
        $this->getJson('/api/permissions')->assertStatus(401);
    }
}
