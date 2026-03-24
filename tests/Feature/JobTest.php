<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobTest extends TestCase
{
    use RefreshDatabase;

    private Employer $employer;
    private string $token;
    private array $validPayload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employer = Employer::factory()->active()->create();
        $this->token = $this->employer->createToken('test')->plainTextToken;

        $this->validPayload = [
            'title' => 'Senior Laravel Developer',
            'description' => 'Build REST APIs for a fintech platform.',
            'skills' => 'PHP, Laravel, MySQL',
            'rate_type' => 'hourly',
            'experience_level' => 'advanced',
            'min_budget' => 30,
            'max_budget' => 80,
            'deadline' => now()->addDays(30)->format('Y-m-d'),
            'estimated_duration' => '3 months',
            'shift_type' => 'Morning',
        ];
    }

    // ─── FUNCTIONAL: Create Job ──────────────────────────────────────

    public function test_employer_can_create_job(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/jobs', $this->validPayload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Job created successfully.',
            ])
            ->assertJsonPath('data.title', 'Senior Laravel Developer')
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('job_postings', [
            'title' => 'Senior Laravel Developer',
            'employer_id' => $this->employer->id,
        ]);
    }

    public function test_unauthenticated_user_cannot_create_job(): void
    {
        $response = $this->postJson('/api/jobs', $this->validPayload);
        $response->assertStatus(401);
    }

    // ─── VALIDATION: Create ──────────────────────────────────────────

    public function test_create_job_fails_without_title(): void
    {
        unset($this->validPayload['title']);
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/jobs', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_create_job_fails_with_invalid_rate_type(): void
    {
        $this->validPayload['rate_type'] = 'monthly';
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/jobs', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('rate_type');
    }

    public function test_create_job_fails_with_past_deadline(): void
    {
        $this->validPayload['deadline'] = '2020-01-01';
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/jobs', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('deadline');
    }

    public function test_create_job_fails_with_invalid_experience_level(): void
    {
        $this->validPayload['experience_level'] = 'expert';
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/jobs', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('experience_level');
    }

    public function test_create_job_fails_with_invalid_shift_type(): void
    {
        $this->validPayload['shift_type'] = 'Midnight';
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/jobs', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('shift_type');
    }

    public function test_create_job_fails_when_max_budget_less_than_min(): void
    {
        $this->validPayload['min_budget'] = 100;
        $this->validPayload['max_budget'] = 50;
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/jobs', $this->validPayload);
        $response->assertStatus(422)->assertJsonValidationErrors('max_budget');
    }

    // ─── FUNCTIONAL: List Jobs ───────────────────────────────────────

    public function test_can_list_jobs_without_auth(): void
    {
        Job::factory()->count(3)->create(['employer_id' => $this->employer->id]);

        $response = $this->getJson('/api/jobs');

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_job_list_supports_search(): void
    {
        Job::factory()->create([
            'employer_id' => $this->employer->id,
            'title' => 'UniqueJobTitle123',
        ]);
        Job::factory()->count(5)->create(['employer_id' => $this->employer->id]);

        $response = $this->getJson('/api/jobs?search=UniqueJobTitle123');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data.data')));
    }

    public function test_job_list_filters_by_rate_type(): void
    {
        Job::factory()->create(['employer_id' => $this->employer->id, 'rate_type' => 'hourly']);
        Job::factory()->create(['employer_id' => $this->employer->id, 'rate_type' => 'fixed']);

        $response = $this->getJson('/api/jobs?rate_type=hourly');

        $response->assertStatus(200);
        foreach ($response->json('data.data') as $job) {
            $this->assertEquals('hourly', $job['rate_type']);
        }
    }

    public function test_job_list_filters_active_only(): void
    {
        Job::factory()->create([
            'employer_id' => $this->employer->id,
            'deadline' => now()->addDays(10),
        ]);
        Job::factory()->create([
            'employer_id' => $this->employer->id,
            'deadline' => now()->subDays(10),
        ]);

        $response = $this->getJson('/api/jobs?active_only=1');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data.data')));
    }

    // ─── FUNCTIONAL: Show Job ────────────────────────────────────────

    public function test_can_show_job_with_employer(): void
    {
        $job = Job::factory()->create(['employer_id' => $this->employer->id]);

        $response = $this->getJson("/api/jobs/{$job->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure(['data' => ['id', 'title', 'employer']]);
    }

    public function test_show_returns_404_for_nonexistent_job(): void
    {
        $response = $this->getJson('/api/jobs/99999');
        $response->assertStatus(404)->assertJson(['success' => false]);
    }

    // ─── FUNCTIONAL: Update Job ──────────────────────────────────────

    public function test_employer_can_update_own_job(): void
    {
        $job = Job::factory()->create(['employer_id' => $this->employer->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Updated Title']);

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Job updated successfully.']);

        $this->assertDatabaseHas('job_postings', ['id' => $job->id, 'title' => 'Updated Title']);
    }

    public function test_employer_cannot_update_another_employers_job(): void
    {
        $otherEmployer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $otherEmployer->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/jobs/{$job->id}", ['title' => 'Stolen']);

        $response->assertStatus(403);
    }

    public function test_update_job_returns_404_for_nonexistent(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/jobs/99999', ['title' => 'Ghost']);

        $response->assertStatus(404);
    }

    // ─── FUNCTIONAL: Delete Job ──────────────────────────────────────

    public function test_employer_can_delete_own_job(): void
    {
        $job = Job::factory()->create(['employer_id' => $this->employer->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/jobs/{$job->id}");

        $response->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Job deleted successfully.']);

        $this->assertDatabaseMissing('job_postings', ['id' => $job->id]);
    }

    public function test_employer_cannot_delete_another_employers_job(): void
    {
        $otherEmployer = Employer::factory()->active()->create();
        $job = Job::factory()->create(['employer_id' => $otherEmployer->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/jobs/{$job->id}");

        $response->assertStatus(403);
    }
}
