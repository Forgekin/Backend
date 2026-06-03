<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The protected freelancer/employer/job routes authenticate with the generic
 * `auth:sanctum` guard, which accepts ANY account type, and the three tables
 * share an id space. These tests pin down that authorization checks the caller's
 * account *type* — not just a matching id — so e.g. freelancer #1 cannot act as
 * employer #1.
 */
class AccountAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_freelancer_cannot_update_an_employer_with_the_same_id(): void
    {
        $freelancer = Freelancer::factory()->verified()->create(); // id 1
        $employer = Employer::factory()->active()->create();       // id 1 (separate table)
        $this->assertSame($freelancer->id, $employer->id);

        Sanctum::actingAs($freelancer);

        $this->putJson("/api/employers/{$employer->id}", ['first_name' => 'Hacked'])
            ->assertStatus(403);

        $this->assertNotSame('Hacked', $employer->fresh()->first_name);
    }

    public function test_freelancer_cannot_delete_an_employer_with_the_same_id(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $employer = Employer::factory()->active()->create();

        Sanctum::actingAs($freelancer);

        $this->deleteJson("/api/employers/{$employer->id}")->assertStatus(403);
        $this->assertDatabaseHas('employers', ['id' => $employer->id]);
    }

    public function test_employer_cannot_update_a_freelancer_with_the_same_id(): void
    {
        $employer = Employer::factory()->active()->create();
        $freelancer = Freelancer::factory()->verified()->create();

        Sanctum::actingAs($employer);

        $this->putJson("/api/freelancers/{$freelancer->id}", ['first_name' => 'Hacked'])
            ->assertStatus(403);

        $this->assertNotSame('Hacked', $freelancer->fresh()->first_name);
    }

    public function test_freelancer_cannot_delete_a_job_owned_by_employer_with_the_same_id(): void
    {
        $employer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $freelancer = Freelancer::factory()->verified()->create();
        $this->assertSame($freelancer->id, $employer->id);

        Sanctum::actingAs($freelancer);

        $this->deleteJson("/api/jobs/{$job->id}")->assertStatus(403);
        $this->assertDatabaseHas('job_postings', ['id' => $job->id]);
    }

    public function test_employer_cannot_spoof_employer_id_when_creating_a_job(): void
    {
        $owner = Employer::factory()->active()->create();
        $victim = Employer::factory()->active()->create();

        Sanctum::actingAs($owner);

        $res = $this->postJson('/api/jobs', [
            'title' => 'Build a landing page',
            'description' => 'Static marketing site.',
            'skills' => 'HTML, CSS',
            'rate_type' => 'fixed',
            'experience_level' => 'intermediate',
            'deadline' => now()->addWeek()->toDateString(),
            'estimated_duration' => '2 weeks',
            'shift_type' => 'Any Shift',
            'employer_id' => $victim->id, // attempt to attribute it to someone else
        ]);

        $res->assertStatus(201);
        $this->assertSame($owner->id, $res->json('data.employer_id'));
        $this->assertNotSame($victim->id, $res->json('data.employer_id'));
    }

    // --- Legitimate flows still work (no over-blocking) ----------------------

    public function test_employer_can_update_their_own_profile(): void
    {
        $employer = Employer::factory()->active()->create();
        Sanctum::actingAs($employer);

        $this->putJson("/api/employers/{$employer->id}", ['first_name' => 'Updated'])
            ->assertStatus(200);

        $this->assertSame('Updated', $employer->fresh()->first_name);
    }

    public function test_freelancer_can_update_their_own_profile(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        Sanctum::actingAs($freelancer);

        $this->putJson("/api/freelancers/{$freelancer->id}", ['first_name' => 'Updated'])
            ->assertStatus(200);

        $this->assertSame('Updated', $freelancer->fresh()->first_name);
    }

    public function test_verify_email_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/freelancers/verify-email', []);
        }

        // The 7th request within the minute is throttled.
        $this->postJson('/api/freelancers/verify-email', [])->assertStatus(429);
    }
}
