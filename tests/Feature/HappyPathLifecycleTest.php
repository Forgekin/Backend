<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\User;
use App\Notifications\FreelancerJobStatusUpdated;
use App\Notifications\JobAssignedToFreelancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * End-to-end happy path exercising the platform's core purpose through the real
 * API: an employer registers → admin verifies → employer posts a job → admin
 * approves → admin assigns a freelancer → admin moves it through to "done".
 */
class HappyPathLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_to_completed_job_lifecycle(): void
    {
        Notification::fake();
        Mail::fake();

        // ── An admin with the permissions this flow needs ──────────────
        foreach (['employers.verify', 'jobs.approve', 'jobs.assign'] as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }
        $adminRole = Role::firstOrCreate(['name' => 'Super-Admin']);
        $adminRole->givePermissionTo(['employers.verify', 'jobs.approve', 'jobs.assign']);
        $admin = User::factory()->create();
        $admin->assignRole($adminRole);
        $adminHeaders = ['Authorization' => 'Bearer ' . $admin->createToken('admin')->plainTextToken];

        // ── 1. Employer registers (lands as pending/inactive) ──────────
        $this->postJson('/api/employers/register', [
            'first_name' => 'Leslie',
            'last_name' => 'Brown',
            'company_name' => 'TechVision Solutions',
            'email' => 'leslie@techvision.com',
            'contact' => '0241234567',
            'business_type' => 'SME',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(201);

        $this->assertDatabaseHas('employers', [
            'email' => 'leslie@techvision.com',
            'verification_status' => 'inactive',
        ]);
        $employer = Employer::where('email', 'leslie@techvision.com')->firstOrFail();

        // ── 2. A pending employer can't log in yet ─────────────────────
        $this->postJson('/api/employers/login', [
            'email' => 'leslie@techvision.com',
            'password' => 'Password1!',
        ])->assertStatus(403)->assertJson(['requires_verification' => true]);

        // ── 3. Admin verifies the employer ─────────────────────────────
        $this->withHeaders($adminHeaders)
            ->patchJson("/api/admin/employers/{$employer->id}/approve")
            ->assertStatus(200);
        $this->assertSame('active', $employer->fresh()->verification_status);

        // ── 4. Employer logs in and gets a token ───────────────────────
        $login = $this->postJson('/api/employers/login', [
            'email' => 'leslie@techvision.com',
            'password' => 'Password1!',
        ]);
        $login->assertStatus(200)->assertJson(['success' => true]);
        $employerHeaders = ['Authorization' => 'Bearer ' . $login->json('token')];

        // ── 5. A freelancer registers and verifies their email ─────────
        $this->postJson('/api/freelancers', [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@dev.com',
            'contact' => '0559876543',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'gender' => 'male',
            'dob' => '1995-05-05',
        ])->assertStatus(201);

        $freelancer = Freelancer::where('email', 'john@dev.com')->firstOrFail();
        $this->postJson('/api/freelancers/verify-email', [
            'email' => 'john@dev.com',
            'code' => $freelancer->verification_code,
        ])->assertStatus(200);
        $this->assertNotNull($freelancer->fresh()->email_verified_at);

        // ── 6. Employer posts a job (starts as "new") ──────────────────
        $post = $this->withHeaders($employerHeaders)->postJson('/api/jobs', [
            'title' => 'Build a marketing site',
            'description' => 'A static marketing site for our product launch.',
            'skills' => 'HTML, CSS, React',
            'rate_type' => 'fixed',
            'experience_level' => 'intermediate',
            'deadline' => now()->addWeeks(3)->toDateString(),
            'estimated_duration' => '3 weeks',
            'shift_type' => 'Any Shift',
            'min_budget' => 1500,
            'max_budget' => 2000,
        ]);
        $post->assertStatus(201);
        $jobId = $post->json('data.id');
        $this->assertDatabaseHas('job_postings', [
            'id' => $jobId,
            'employer_id' => $employer->id,
            'status' => 'new',
        ]);

        // ── 7. Admin approves the job (records the agreed rate) ────────
        $this->withHeaders($adminHeaders)
            ->patchJson("/api/admin/jobs/{$jobId}/approve", ['agreed_rate' => 1800])
            ->assertStatus(200);
        $this->assertSame('approved', Job::find($jobId)->status);

        // ── 8. Admin assigns the freelancer ────────────────────────────
        $this->withHeaders($adminHeaders)->patchJson("/api/admin/jobs/{$jobId}/assign", [
            'freelancer_id' => $freelancer->id,
            'freelancer_amount' => 1650,
            'actual_start_date' => now()->addDays(2)->toDateString(),
        ])->assertStatus(200);

        $assigned = Job::find($jobId);
        $this->assertSame('assigned', $assigned->status);
        $this->assertSame($freelancer->id, $assigned->assigned_freelancer_id);
        Notification::assertSentTo($freelancer, JobAssignedToFreelancer::class);

        // ── 9. Admin advances the job through to completion ────────────
        $this->withHeaders($adminHeaders)
            ->patchJson("/api/admin/jobs/{$jobId}/status", ['status' => 'in_progress'])
            ->assertStatus(200);
        $this->withHeaders($adminHeaders)
            ->patchJson("/api/admin/jobs/{$jobId}/status", ['status' => 'done'])
            ->assertStatus(200);

        // ── Final state ────────────────────────────────────────────────
        $final = Job::find($jobId);
        $this->assertSame('done', $final->status);
        $this->assertSame($freelancer->id, $final->assigned_freelancer_id);
        $this->assertEquals(1800, $final->agreed_rate);
        $this->assertEquals(1650, $final->freelancer_amount);
        Notification::assertSentTo($freelancer, FreelancerJobStatusUpdated::class);
    }
}
