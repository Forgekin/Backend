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

/**
 * Server-side guard: a job's lifecycle status can only be advanced once a
 * freelancer is assigned (mirrors the disabled UI control).
 */
class JobStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    private function opsToken(): string
    {
        Permission::firstOrCreate(['name' => 'jobs.assign']);
        $role = Role::firstOrCreate(['name' => 'Ops']);
        $role->givePermissionTo('jobs.assign');
        $user = User::factory()->create();
        $user->assignRole($role);
        return $user->createToken('test')->plainTextToken;
    }

    public function test_cannot_change_status_of_unassigned_job(): void
    {
        $token = $this->opsToken();
        $employer = Employer::factory()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => null,
            'status' => 'approved',
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/admin/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertStatus(422);

        $this->assertSame('approved', $job->fresh()->status);
    }

    public function test_can_change_status_of_assigned_job(): void
    {
        $token = $this->opsToken();
        $employer = Employer::factory()->create();
        $freelancer = Freelancer::factory()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'assigned',
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->patchJson("/api/admin/jobs/{$job->id}/status", ['status' => 'in_progress'])
            ->assertStatus(200);

        $this->assertSame('in_progress', $job->fresh()->status);
    }
}
