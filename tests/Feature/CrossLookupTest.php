<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\JobPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrossLookupTest extends TestCase
{
    use RefreshDatabase;

    // ─── Freelancer → their assigned jobs ────────────────────────────

    public function test_freelancer_can_list_jobs_assigned_to_them(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $employer = Employer::factory()->active()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        Job::factory()->count(3)->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'in_progress',
        ]);
        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'done',
        ]);
        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => null,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/freelancers/{$freelancer->id}/jobs");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.total', 4);
    }

    public function test_assigned_jobs_respects_status_filter(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $employer = Employer::factory()->active()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        Job::factory()->count(2)->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'in_progress',
        ]);
        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'done',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/freelancers/{$freelancer->id}/jobs?status=in_progress");

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 2);
    }

    public function test_assigned_jobs_active_only_filter_excludes_done(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $employer = Employer::factory()->active()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'in_progress',
        ]);
        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'done',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/freelancers/{$freelancer->id}/jobs?active_only=1");

        $response->assertStatus(200)->assertJsonPath('data.total', 1);
    }

    public function test_freelancer_cannot_list_another_freelancers_jobs(): void
    {
        $me = Freelancer::factory()->verified()->create();
        $other = Freelancer::factory()->verified()->create();
        $token = $me->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/freelancers/{$other->id}/jobs")
            ->assertStatus(403);
    }

    public function test_assigned_jobs_requires_auth(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $this->getJson("/api/freelancers/{$freelancer->id}/jobs")->assertStatus(401);
    }

    // ─── Employer → all freelancers across their jobs ────────────────

    public function test_employer_can_list_freelancers_on_their_jobs(): void
    {
        $employer = Employer::factory()->active()->create();
        $token = $employer->createToken('test')->plainTextToken;

        $f1 = Freelancer::factory()->verified()->create(['first_name' => 'Ada']);
        $f2 = Freelancer::factory()->verified()->create(['first_name' => 'Bernard']);

        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $f1->id,
            'status' => 'in_progress',
        ]);
        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $f1->id,
            'status' => 'done',
        ]);
        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $f2->id,
            'status' => 'done',
        ]);

        JobPayment::create([
            'job_id' => Job::where('assigned_freelancer_id', $f1->id)->where('status', 'done')->first()->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $f1->id,
            'gross' => 1800, 'platform_fee' => 144, 'tax' => 90, 'net' => 1566,
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/employers/{$employer->id}/freelancers");

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 2);

        $items = collect($response->json('data.data'));
        $ada = $items->firstWhere('id', $f1->id);
        $this->assertSame(1, $ada['jobs_in_progress']);
        $this->assertSame(1, $ada['jobs_completed_for_you']);
        $this->assertEquals(1800, $ada['total_spend_on_freelancer']);
    }

    public function test_employer_freelancers_active_filter(): void
    {
        $employer = Employer::factory()->active()->create();
        $token = $employer->createToken('test')->plainTextToken;

        $active = Freelancer::factory()->verified()->create();
        $doneOnly = Freelancer::factory()->verified()->create();

        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $active->id,
            'status' => 'in_progress',
        ]);
        Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $doneOnly->id,
            'status' => 'done',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/employers/{$employer->id}/freelancers?status=active");

        $response->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    public function test_employer_cannot_list_another_employers_freelancers(): void
    {
        $me = Employer::factory()->active()->create();
        $other = Employer::factory()->active()->create();
        $token = $me->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/employers/{$other->id}/freelancers")
            ->assertStatus(403);
    }

    public function test_employer_freelancers_requires_auth(): void
    {
        $employer = Employer::factory()->active()->create();
        $this->getJson("/api/employers/{$employer->id}/freelancers")->assertStatus(401);
    }

    // ─── Job → freelancer assigned to it ─────────────────────────────

    public function test_employer_can_see_freelancer_assigned_to_their_job(): void
    {
        $employer = Employer::factory()->active()->create();
        $freelancer = Freelancer::factory()->verified()->create([
            'first_name' => 'Sodey', 'last_name' => 'Haidor', 'other_names' => null,
        ]);
        $token = $employer->createToken('test')->plainTextToken;

        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'in_progress',
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/jobs/{$job->id}/freelancer");

        $response->assertStatus(200)
            ->assertJsonPath('data.freelancer.id', $freelancer->id)
            ->assertJsonPath('data.freelancer.full_name', 'Sodey Haidor')
            ->assertJsonPath('data.job.id', $job->id);
    }

    public function test_assigned_freelancer_can_see_themselves_on_the_job(): void
    {
        $employer = Employer::factory()->active()->create();
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'in_progress',
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/jobs/{$job->id}/freelancer")
            ->assertStatus(200)
            ->assertJsonPath('data.freelancer.id', $freelancer->id);
    }

    public function test_random_freelancer_cannot_see_freelancer_on_someone_elses_job(): void
    {
        $employer = Employer::factory()->active()->create();
        $assigned = Freelancer::factory()->verified()->create();
        $randomer = Freelancer::factory()->verified()->create();
        $token = $randomer->createToken('test')->plainTextToken;

        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $assigned->id,
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/jobs/{$job->id}/freelancer")
            ->assertStatus(403);
    }

    public function test_admin_with_jobs_read_permission_can_see_freelancer_on_any_job(): void
    {
        Permission::create(['name' => 'jobs.read']);
        $role = Role::create(['name' => 'Super-Admin']);
        $role->givePermissionTo('jobs.read');

        $admin = User::factory()->create();
        $admin->assignRole('Super-Admin');
        $token = $admin->createToken('test')->plainTextToken;

        $employer = Employer::factory()->active()->create();
        $freelancer = Freelancer::factory()->verified()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
        ]);

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/jobs/{$job->id}/freelancer")
            ->assertStatus(200)
            ->assertJsonPath('data.freelancer.id', $freelancer->id);
    }

    public function test_freelancer_endpoint_returns_null_when_no_one_assigned(): void
    {
        $employer = Employer::factory()->active()->create();
        $token = $employer->createToken('test')->plainTextToken;

        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => null,
        ]);

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson("/api/jobs/{$job->id}/freelancer");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => null,
                'message' => 'No freelancer has been assigned to this job yet.',
            ]);
    }

    public function test_freelancer_endpoint_returns_404_for_missing_job(): void
    {
        $employer = Employer::factory()->active()->create();
        $token = $employer->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/jobs/99999/freelancer')
            ->assertStatus(404);
    }

    public function test_freelancer_endpoint_requires_auth(): void
    {
        $job = Job::factory()->create([
            'employer_id' => Employer::factory()->active()->create()->id,
        ]);

        $this->getJson("/api/jobs/{$job->id}/freelancer")->assertStatus(401);
    }
}
