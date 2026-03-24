<?php

namespace Tests\Feature;

use App\Models\Freelancer;
use App\Models\Shift;
use App\Models\Skill;
use App\Models\WorkExperience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreelancerCrudTest extends TestCase
{
    use RefreshDatabase;

    // ─── FUNCTIONAL: Index / List ────────────────────────────────────

    public function test_can_list_freelancers(): void
    {
        Freelancer::factory()->count(3)->create();

        $response = $this->getJson('/api/freelancers');

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    public function test_freelancer_list_is_paginated(): void
    {
        Freelancer::factory()->count(20)->create();

        $response = $this->getJson('/api/freelancers?per_page=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_freelancer_search_by_name(): void
    {
        Freelancer::factory()->create(['first_name' => 'UniqueSearchName']);
        Freelancer::factory()->count(5)->create();

        $response = $this->getJson('/api/freelancers?search=UniqueSearchName');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('data')));
    }

    public function test_freelancer_filter_by_verified(): void
    {
        Freelancer::factory()->verified()->count(2)->create();
        Freelancer::factory()->count(3)->create(); // unverified

        $response = $this->getJson('/api/freelancers?verified=1');

        $response->assertStatus(200);
        $this->assertEquals(2, count($response->json('data')));
    }

    // ─── FUNCTIONAL: Show ────────────────────────────────────────────

    public function test_can_show_freelancer_with_relationships(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();

        $response = $this->getJson("/api/freelancers/{$freelancer->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['id', 'first_name', 'last_name', 'skills', 'work_experiences', 'shift_preferences'],
            ]);
    }

    public function test_show_returns_404_for_nonexistent_freelancer(): void
    {
        $response = $this->getJson('/api/freelancers/99999');
        $response->assertStatus(404);
    }

    // ─── FUNCTIONAL: Update ──────────────────────────────────────────

    public function test_freelancer_can_update_own_profile(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/freelancers/{$freelancer->id}", [
                'first_name' => 'UpdatedName',
                'profession' => 'Backend Developer',
                'bio' => 'I build APIs.',
                'location' => 'Accra, Ghana',
                'hourly_rate' => 50.00,
            ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Profile updated successfully']);

        $freelancer->refresh();
        $this->assertEquals('UpdatedName', $freelancer->first_name);
        $this->assertEquals('Backend Developer', $freelancer->profession);
    }

    public function test_freelancer_cannot_update_another_freelancer(): void
    {
        $freelancer1 = Freelancer::factory()->verified()->create();
        $freelancer2 = Freelancer::factory()->verified()->create();
        $token = $freelancer1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/freelancers/{$freelancer2->id}", [
                'first_name' => 'Hacked',
            ]);

        $response->assertStatus(403);
    }

    public function test_update_can_sync_skills(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/freelancers/{$freelancer->id}", [
                'skills' => ['PHP', 'Laravel', 'Docker'],
            ]);

        $response->assertStatus(200);
        $this->assertEquals(3, $freelancer->skills()->count());
        $this->assertTrue(Skill::where('name', 'PHP')->exists());
    }

    public function test_update_can_add_work_experiences(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/freelancers/{$freelancer->id}", [
                'work_experiences' => [
                    [
                        'role' => 'Senior Developer',
                        'company_name' => 'TechCorp',
                        'start_date' => '2020-01-01',
                        'end_date' => '2023-12-31',
                        'description' => 'Built APIs',
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $freelancer->workExperiences()->count());
    }

    public function test_update_rejects_work_experience_with_end_before_start(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/freelancers/{$freelancer->id}", [
                'work_experiences' => [
                    [
                        'role' => 'Dev',
                        'company_name' => 'Corp',
                        'start_date' => '2023-06-01',
                        'end_date' => '2022-01-01',
                    ],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'End date cannot be before start date.']);
    }

    public function test_update_can_sync_shift_preferences(): void
    {
        $this->seed(\Database\Seeders\ShiftSeeder::class);
        $shift = Shift::first();

        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->putJson("/api/freelancers/{$freelancer->id}", [
                'shift_preferences' => [
                    [
                        'shift_id' => $shift->id,
                        'start_time' => '08:00:00',
                        'end_time' => '12:00:00',
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $this->assertEquals(1, $freelancer->shifts()->count());
    }

    public function test_unauthenticated_user_cannot_update_freelancer(): void
    {
        $freelancer = Freelancer::factory()->create();

        $response = $this->putJson("/api/freelancers/{$freelancer->id}", [
            'first_name' => 'Hacker',
        ]);

        $response->assertStatus(401);
    }

    // ─── FUNCTIONAL: Destroy ─────────────────────────────────────────

    public function test_freelancer_can_delete_own_account(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/freelancers/{$freelancer->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Freelancer deleted successfully']);

        $this->assertDatabaseMissing('freelancers', ['id' => $freelancer->id]);
    }

    public function test_freelancer_cannot_delete_another_freelancer(): void
    {
        $freelancer1 = Freelancer::factory()->verified()->create();
        $freelancer2 = Freelancer::factory()->verified()->create();
        $token = $freelancer1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/freelancers/{$freelancer2->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('freelancers', ['id' => $freelancer2->id]);
    }

    // ─── FUNCTIONAL: Delete Work Experience ──────────────────────────

    public function test_freelancer_can_delete_own_work_experience(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $experience = WorkExperience::create([
            'freelancer_id' => $freelancer->id,
            'role' => 'Dev',
            'company_name' => 'Corp',
            'start_date' => '2020-01-01',
        ]);
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/freelancers/{$freelancer->id}/work-experiences/{$experience->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Work experience deleted successfully']);
    }

    public function test_delete_work_experience_returns_404_for_invalid_id(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/freelancers/{$freelancer->id}/work-experiences/99999");

        $response->assertStatus(404);
    }

    public function test_freelancer_cannot_delete_another_freelancers_work_experience(): void
    {
        $freelancer1 = Freelancer::factory()->verified()->create();
        $freelancer2 = Freelancer::factory()->verified()->create();
        $experience = WorkExperience::create([
            'freelancer_id' => $freelancer2->id,
            'role' => 'Dev',
            'company_name' => 'Corp',
            'start_date' => '2020-01-01',
        ]);
        $token = $freelancer1->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/freelancers/{$freelancer2->id}/work-experiences/{$experience->id}");

        $response->assertStatus(403);
    }

    // ─── FUNCTIONAL: Detach Skill ────────────────────────────────────

    public function test_freelancer_can_detach_own_skill(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $skill = Skill::create(['name' => 'PHP']);
        $freelancer->skills()->attach($skill->id);
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/freelancers/{$freelancer->id}/skills/{$skill->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Skill detached successfully']);
        $this->assertEquals(0, $freelancer->skills()->count());
    }

    public function test_detach_skill_returns_404_for_unattached_skill(): void
    {
        $freelancer = Freelancer::factory()->verified()->create();
        $skill = Skill::create(['name' => 'PHP']);
        $token = $freelancer->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->deleteJson("/api/freelancers/{$freelancer->id}/skills/{$skill->id}");

        $response->assertStatus(404);
    }
}
