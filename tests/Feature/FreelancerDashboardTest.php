<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\FreelancerWithdrawal;
use App\Models\Job;
use App\Models\JobHour;
use App\Models\JobPayment;
use App\Models\JobReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreelancerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Freelancer $freelancer;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freelancer = Freelancer::factory()->verified()->create([
            'first_name' => 'Sodey',
            'last_name' => 'Haidor',
        ]);
        $this->token = $this->freelancer->createToken('test')->plainTextToken;
    }

    public function test_dashboard_returns_empty_stats_for_fresh_freelancer(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/freelancers/{$this->freelancer->id}/dashboard");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.stats.jobs_completed', 0)
            ->assertJsonPath('data.stats.total_jobs', 0)
            ->assertJsonPath('data.earnings.total_earned', 0)
            ->assertJsonPath('data.earnings.net_earned', 0)
            ->assertJsonPath('data.job_history.count', 0)
            ->assertJsonPath('data.active_jobs', [])
            ->assertJsonStructure([
                'data' => [
                    'freelancer' => ['id', 'full_name', 'verification_status', 'profile_image_url'],
                    'stats' => ['jobs_completed', 'completion_rate', 'average_rating'],
                    'earnings' => ['currency', 'total_earned', 'by_period', 'by_rate_type', 'monthly_trend'],
                    'top_skills', 'top_clients', 'active_jobs',
                    'job_history' => ['count', 'page', 'per_page', 'items'],
                    'withdrawals' => ['total_withdrawn', 'last_withdrawal'],
                    'meta' => ['generated_at', 'currency_symbol'],
                ],
            ]);
    }

    public function test_dashboard_aggregates_jobs_payments_and_reviews(): void
    {
        $employer = Employer::factory()->active()->create(['company_name' => 'TechVision Solutions']);

        $doneJob = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $this->freelancer->id,
            'status' => 'done',
            'rate_type' => 'fixed',
            'max_budget' => 1800,
            'skills' => 'PHP, Laravel',
            'actual_start_date' => now()->subDays(30),
            'completed_at' => now()->subDays(2),
            'deadline' => now()->addDay()->toDateString(),
        ]);

        JobPayment::create([
            'job_id' => $doneJob->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $this->freelancer->id,
            'gross' => 1800,
            'platform_fee' => 144,
            'tax' => 90,
            'net' => 1566,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
            'invoice_id' => 'INV-TEST-1',
        ]);

        JobReview::create([
            'job_id' => $doneJob->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $this->freelancer->id,
            'stars' => 5,
            'review_text' => 'Excellent work',
            'reviewed_at' => now(),
        ]);

        $activeJob = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $this->freelancer->id,
            'status' => 'in_progress',
            'rate_type' => 'hourly',
            'agreed_rate' => 45.00,
            'actual_start_date' => now()->subDays(5),
            'deadline' => now()->addDays(20)->toDateString(),
        ]);

        JobHour::create([
            'job_id' => $activeJob->id,
            'freelancer_id' => $this->freelancer->id,
            'hours' => 12,
            'logged_for' => now()->subDay()->toDateString(),
        ]);

        FreelancerWithdrawal::create([
            'freelancer_id' => $this->freelancer->id,
            'amount' => 500,
            'method' => 'mobile_money',
            'destination' => 'MTN 0551234567',
            'status' => 'completed',
            'requested_at' => now()->subDays(3),
            'settled_at' => now()->subDays(2),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/freelancers/{$this->freelancer->id}/dashboard");

        $response->assertStatus(200)
            ->assertJsonPath('data.stats.jobs_completed', 1)
            ->assertJsonPath('data.stats.jobs_in_progress', 1)
            ->assertJsonPath('data.stats.total_jobs', 2)
            ->assertJsonPath('data.stats.average_rating', 5)
            ->assertJsonPath('data.earnings.total_paid', 1800)
            ->assertJsonPath('data.earnings.net_earned', 1566)
            ->assertJsonPath('data.earnings.by_rate_type.fixed.jobs', 1)
            ->assertJsonPath('data.earnings.by_rate_type.fixed.earned', 1800)
            ->assertJsonPath('data.withdrawals.total_withdrawn', 500)
            ->assertJsonPath('data.job_history.count', 1)
            ->assertJsonPath('data.job_history.items.0.id', $doneJob->id)
            ->assertJsonPath('data.job_history.items.0.rating.stars', 5)
            ->assertJsonPath('data.job_history.items.0.payment.status', 'paid');

        $active = $response->json('data.active_jobs');
        $this->assertCount(1, $active);
        $this->assertEquals($activeJob->id, $active[0]['id']);
        $this->assertEquals(12, $active[0]['hours_logged']);
        $this->assertEquals(540, $active[0]['earnings_so_far']);

        $topClients = $response->json('data.top_clients');
        $this->assertCount(1, $topClients);
        $this->assertEquals('TechVision Solutions', $topClients[0]['company_name']);
        $this->assertEquals(1800, $topClients[0]['total_earned']);
    }

    public function test_freelancer_cannot_view_another_freelancers_dashboard(): void
    {
        $other = Freelancer::factory()->verified()->create();

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/freelancers/{$other->id}/dashboard")
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_view_dashboard(): void
    {
        $this->getJson("/api/freelancers/{$this->freelancer->id}/dashboard")
            ->assertStatus(401);
    }
}
