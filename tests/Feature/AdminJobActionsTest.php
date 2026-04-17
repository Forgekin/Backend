<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminJobActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'jobs.approve']);
        Permission::create(['name' => 'jobs.assign']);

        $role = Role::create(['name' => 'Super-Admin']);
        $role->givePermissionTo(['jobs.approve', 'jobs.assign']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super-Admin');
        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    // ─── Approve ─────────────────────────────────────────────────────

    public function test_admin_can_approve_job(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'status' => 'pending_approval',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/approve");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Job approved successfully.']);

        $this->assertDatabaseHas('job_postings', [
            'id' => $job->id,
            'status' => 'approved',
        ]);
    }

    public function test_approve_is_idempotent_when_already_approved(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'status' => 'approved',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/approve");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Job is already approved.']);
    }

    public function test_approve_returns_404_for_nonexistent_job(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson('/api/admin/jobs/99999/approve')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Job not found.']);
    }

    // ─── Assign Freelancer ───────────────────────────────────────────

    public function test_admin_can_assign_freelancer_to_job(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $freelancer = Freelancer::factory()->verified()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/assign", [
                'freelancer_id' => $freelancer->id,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Freelancer assigned successfully.']);

        $this->assertDatabaseHas('job_postings', [
            'id' => $job->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'assigned',
        ]);
    }

    public function test_assign_fails_without_freelancer_id(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/assign", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_id');
    }

    public function test_assign_fails_for_nonexistent_freelancer(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/assign", [
                'freelancer_id' => 99999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('freelancer_id');
    }

    public function test_assign_returns_404_for_nonexistent_job(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson('/api/admin/jobs/99999/assign', [
                'freelancer_id' => $freelancer->id,
            ])
            ->assertStatus(404);
    }

    public function test_unauthenticated_cannot_access_admin_job_actions(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);

        $this->patchJson("/api/admin/jobs/{$job->id}/approve")->assertStatus(401);
        $this->patchJson("/api/admin/jobs/{$job->id}/assign")->assertStatus(401);
    }
}
