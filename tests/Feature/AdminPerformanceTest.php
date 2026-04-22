<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\FreelancerWithdrawal;
use App\Models\Job;
use App\Models\JobHour;
use App\Models\JobPayment;
use App\Models\JobReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'admin.dashboard']);
        $role = Role::create(['name' => 'Super-Admin']);
        $role->givePermissionTo('admin.dashboard');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super-Admin');
        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    public function test_dashboard_returns_empty_shape_for_fresh_platform(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/performance');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.headline.total_freelancers', 0)
            ->assertJsonPath('data.jobs.total_jobs_posted', 0)
            ->assertJsonPath('data.financials.gross_transacted', 0)
            ->assertJsonPath('data.financials.platform_profit', 0)
            ->assertJsonPath('data.freelancer_breakdown.count', 0)
            ->assertJsonPath('data.recent_jobs.count', 0)
            ->assertJsonStructure([
                'data' => [
                    'filters' => ['date_range' => ['from', 'to', 'preset'], 'currency'],
                    'headline', 'jobs' => ['by_status', 'by_rate_type'],
                    'financials', 'trends' => ['monthly'],
                    'top_freelancers', 'top_employers',
                    'freelancer_breakdown' => ['count', 'page', 'per_page', 'items'],
                    'recent_jobs' => ['count', 'page', 'per_page', 'items'],
                    'alerts', 'meta' => ['generated_at', 'queried_by'],
                ],
            ]);
    }

    public function test_dashboard_aggregates_platform_wide_activity(): void
    {
        $employer = Employer::factory()->active()->create(['company_name' => 'TechVision Solutions']);
        $pendingEmployer = Employer::factory()->create(['verification_status' => 'inactive']);

        $freelancer = Freelancer::factory()->verified()->create([
            'first_name' => 'Sodey', 'last_name' => 'Haidor',
        ]);

        $doneJob = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'done',
            'rate_type' => 'fixed',
            'max_budget' => 1800,
            'agreed_rate' => 1800,
            'actual_start_date' => now()->subDays(30),
            'completed_at' => now()->subDays(2),
            'deadline' => now()->addDay()->toDateString(),
        ]);

        JobPayment::create([
            'job_id' => $doneJob->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $freelancer->id,
            'gross' => 1800, 'platform_fee' => 144, 'tax' => 90, 'net' => 1566,
            'status' => 'paid',
            'paid_at' => now()->subDay(),
            'invoice_id' => 'INV-T1',
        ]);

        JobReview::create([
            'job_id' => $doneJob->id,
            'employer_id' => $employer->id,
            'freelancer_id' => $freelancer->id,
            'stars' => 5,
            'review_text' => 'Great',
            'reviewed_at' => now(),
        ]);

        $activeJob = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'in_progress',
            'rate_type' => 'hourly',
            'agreed_rate' => 45,
            'actual_start_date' => now()->subDays(5),
            'deadline' => now()->addDays(20)->toDateString(),
        ]);
        JobHour::create([
            'job_id' => $activeJob->id, 'freelancer_id' => $freelancer->id,
            'hours' => 12, 'logged_for' => now()->subDay()->toDateString(),
        ]);

        FreelancerWithdrawal::create([
            'freelancer_id' => $freelancer->id,
            'amount' => 500, 'method' => 'mobile_money', 'destination' => 'MTN 0551234567',
            'status' => 'completed',
            'requested_at' => now()->subDays(3), 'settled_at' => now()->subDays(2),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/performance?preset=all_time');

        $response->assertStatus(200)
            ->assertJsonPath('data.headline.total_freelancers', 1)
            ->assertJsonPath('data.headline.total_employers', 2)
            ->assertJsonPath('data.headline.pending_employer_verifications', 1)
            ->assertJsonPath('data.jobs.total_jobs_posted', 2)
            ->assertJsonPath('data.jobs.total_jobs_completed', 1)
            ->assertJsonPath('data.jobs.total_jobs_in_progress', 1)
            ->assertJsonPath('data.financials.gross_transacted', 1800)
            ->assertJsonPath('data.financials.platform_profit', 144)
            ->assertJsonPath('data.financials.taxes_collected', 90)
            ->assertJsonPath('data.financials.freelancer_earnings_paid', 1566)
            ->assertJsonPath('data.financials.total_withdrawn_by_freelancers', 500)
            ->assertJsonPath('data.financials.profit_margin_percent', 8);

        $this->assertCount(1, $response->json('data.top_freelancers'));
        $this->assertEquals($freelancer->id, $response->json('data.top_freelancers.0.id'));
        $this->assertEquals(1800, $response->json('data.top_freelancers.0.gross_earned'));

        $this->assertCount(1, $response->json('data.top_employers'));
        $this->assertEquals('TechVision Solutions', $response->json('data.top_employers.0.company_name'));

        $alerts = collect($response->json('data.alerts'));
        $this->assertTrue($alerts->contains('type', 'pending_employer_verification'));
    }

    public function test_respects_date_range_preset(): void
    {
        $employer = Employer::factory()->active()->create();
        $freelancer = Freelancer::factory()->verified()->create();

        $old = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'done',
            'rate_type' => 'fixed',
            'created_at' => now()->subMonths(8),
            'completed_at' => now()->subMonths(8),
        ]);
        $recent = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
            'status' => 'done',
            'rate_type' => 'fixed',
            'created_at' => now()->subDays(5),
            'completed_at' => now()->subDays(5),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/performance?preset=this_month');

        $response->assertStatus(200)
            ->assertJsonPath('data.filters.date_range.preset', 'this_month')
            ->assertJsonPath('data.jobs.total_jobs_posted', 1);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        Role::create(['name' => 'PlainUser']);
        $user = User::factory()->create();
        $user->assignRole('PlainUser');
        $token = $user->createToken('x')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/performance')
            ->assertStatus(403);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->getJson('/api/admin/performance')->assertStatus(401);
    }
}
