<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\User;
use App\Notifications\AdminJobStatusUpdated;
use App\Notifications\EmployerJobStatusUpdated;
use App\Notifications\JobAssignedToFreelancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
        Permission::create(['name' => 'jobs.reject']);
        Permission::create(['name' => 'jobs.assign']);

        $role = Role::create(['name' => 'Super-Admin']);
        $role->givePermissionTo(['jobs.approve', 'jobs.reject', 'jobs.assign']);

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
            ->patchJson("/api/admin/jobs/{$job->id}/approve", ['agreed_rate' => 50]);

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
            ->patchJson("/api/admin/jobs/{$job->id}/approve", ['agreed_rate' => 50]);

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

    // ─── Reject ──────────────────────────────────────────────────────

    public function test_admin_can_reject_job_with_reason(): void
    {
        Notification::fake();
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'status' => 'pending_approval',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/reject", [
                'reason' => 'Budget is below the platform minimum.',
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Job rejected successfully.']);

        $this->assertDatabaseHas('job_postings', [
            'id' => $job->id,
            'status' => 'rejected',
            'rejection_reason' => 'Budget is below the platform minimum.',
        ]);

        Notification::assertSentTo($employer, EmployerJobStatusUpdated::class);
    }

    public function test_reject_works_without_a_reason(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id, 'status' => 'new']);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/reject")
            ->assertStatus(200);

        $this->assertDatabaseHas('job_postings', [
            'id' => $job->id,
            'status' => 'rejected',
            'rejection_reason' => null,
        ]);
    }

    public function test_reject_is_idempotent_when_already_rejected(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id, 'status' => 'rejected']);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/reject")
            ->assertStatus(200)
            ->assertJson(['message' => 'Job is already rejected.']);
    }

    public function test_reject_returns_404_for_nonexistent_job(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson('/api/admin/jobs/99999/reject')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Job not found.']);
    }

    public function test_admin_role_can_reject_job(): void
    {
        // A plain "Admin" (not Super-Admin) holding jobs.reject can reject.
        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo('jobs.reject');
        $adminUser = User::factory()->create();
        $adminUser->assignRole('Admin');
        $token = $adminUser->createToken('test')->plainTextToken;

        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id, 'status' => 'pending_approval']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/jobs/{$job->id}/reject")
            ->assertStatus(200);

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'status' => 'rejected']);
    }

    public function test_user_without_reject_permission_cannot_reject(): void
    {
        $plainRole = Role::create(['name' => 'Viewer']);
        $plainRole->givePermissionTo('jobs.approve'); // has approve but not reject
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        $token = $user->createToken('test')->plainTextToken;

        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id, 'status' => 'pending_approval']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/jobs/{$job->id}/reject")
            ->assertStatus(403);
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
                'freelancer_amount' => 500,
                'actual_start_date' => now()->addDay()->toDateString(),
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

    public function test_employer_notified_when_freelancer_assigned(): void
    {
        Notification::fake();
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $freelancer = Freelancer::factory()->verified()->create();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/assign", [
                'freelancer_id' => $freelancer->id,
                'freelancer_amount' => 500,
                'actual_start_date' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(200);

        Notification::assertSentTo($employer, EmployerJobStatusUpdated::class);
    }

    public function test_assignment_email_includes_start_date_and_amount(): void
    {
        Notification::fake();
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $freelancer = Freelancer::factory()->verified()->create();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/assign", [
                'freelancer_id' => $freelancer->id,
                'freelancer_amount' => 750,
                'actual_start_date' => '2026-06-15',
            ])->assertStatus(200);

        Notification::assertSentTo(
            $freelancer,
            JobAssignedToFreelancer::class,
            function ($notification) use ($freelancer) {
                $lines = implode("\n", $notification->toMail($freelancer)->introLines);
                return str_contains($lines, 'Start date:')
                    && str_contains($lines, "Amount you'll receive:")
                    && str_contains($lines, '750.00');
            }
        );
    }

    public function test_employer_notified_when_job_approved(): void
    {
        Notification::fake();
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'status' => 'pending_approval',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->patchJson("/api/admin/jobs/{$job->id}/approve", ['agreed_rate' => 50])
            ->assertStatus(200);

        Notification::assertSentTo(
            $employer,
            EmployerJobStatusUpdated::class,
            function ($notification) use ($employer) {
                $lines = implode("\n", $notification->toMail($employer)->introLines);
                return str_contains($lines, 'Agreed rate:') && str_contains($lines, '50.00');
            }
        );

        // Admins are alerted too (the acting Super-Admin is an admin recipient).
        Notification::assertSentTo($this->admin, AdminJobStatusUpdated::class);
    }

    public function test_unauthenticated_cannot_access_admin_job_actions(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);

        $this->patchJson("/api/admin/jobs/{$job->id}/approve")->assertStatus(401);
        $this->patchJson("/api/admin/jobs/{$job->id}/assign")->assertStatus(401);
    }
}
