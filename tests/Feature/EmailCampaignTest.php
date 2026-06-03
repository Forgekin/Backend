<?php

namespace Tests\Feature;

use App\Models\EmailCampaign;
use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\User;
use App\Services\CampaignDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmailCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Super-Admin']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super-Admin');
        $this->token = $this->admin->createToken('test')->plainTextToken;
    }

    private function auth()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token}");
    }

    // ─── Access control ──────────────────────────────────────────────

    public function test_unauthenticated_cannot_list(): void
    {
        $this->getJson('/api/admin/campaigns')->assertStatus(401);
    }

    public function test_plain_user_is_forbidden(): void
    {
        Role::create(['name' => 'Viewer']);
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        $token = $user->createToken('x')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/campaigns')
            ->assertStatus(403);
    }

    public function test_user_with_campaigns_permission_is_allowed(): void
    {
        Permission::create(['name' => 'campaigns.manage']);
        $role = Role::create(['name' => 'Marketer']);
        $role->givePermissionTo('campaigns.manage');

        $user = User::factory()->create();
        $user->assignRole('Marketer');
        $token = $user->createToken('x')->plainTextToken;

        $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/admin/campaigns')
            ->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ─── Listing & audiences ─────────────────────────────────────────

    public function test_admin_can_list_campaigns(): void
    {
        $this->auth()->getJson('/api/admin/campaigns')
            ->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['data', 'total', 'current_page']]);
    }

    public function test_audiences_returns_recipient_counts(): void
    {
        Freelancer::factory()->count(3)->create();
        Employer::factory()->count(2)->create();

        $res = $this->auth()->getJson('/api/admin/campaigns/audiences');

        $res->assertStatus(200)
            ->assertJsonPath('data.freelancers', 3)
            ->assertJsonPath('data.employers', 2)
            ->assertJsonPath('data.system_users', 1); // the admin from setUp

        // everyone = distinct emails across all groups (3 + 2 + 1)
        $this->assertSame(6, $res->json('data.everyone'));
    }

    // ─── Creating ────────────────────────────────────────────────────

    public function test_can_save_a_draft(): void
    {
        $res = $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'June newsletter',
            'body' => '<p>Hello world, here is our update.</p>',
            'audience' => 'freelancers',
            'action' => 'draft',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.audience', 'freelancers');

        $this->assertDatabaseHas('email_campaigns', [
            'subject' => 'June newsletter',
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->auth()->postJson('/api/admin/campaigns', [
            'body' => 'short', 'audience' => 'freelancers', 'action' => 'draft',
        ])->assertStatus(422)->assertJsonValidationErrors(['subject']);

        $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'Hi', 'body' => '<p>Long enough body here.</p>',
            'audience' => 'martians', 'action' => 'draft',
        ])->assertStatus(422)->assertJsonValidationErrors(['audience']);
    }

    public function test_schedule_requires_a_future_datetime(): void
    {
        $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'Scheduled', 'body' => '<p>Body content here.</p>',
            'audience' => 'everyone', 'action' => 'schedule',
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);

        $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'Scheduled', 'body' => '<p>Body content here.</p>',
            'audience' => 'everyone', 'action' => 'schedule',
            'scheduled_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_can_schedule_a_campaign(): void
    {
        $when = now()->addDays(2);

        $res = $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'Future blast', 'body' => '<p>See you soon.</p>',
            'audience' => 'employers', 'action' => 'schedule',
            'scheduled_at' => $when->toIso8601String(),
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'scheduled');
        $this->assertDatabaseHas('email_campaigns', ['subject' => 'Future blast', 'status' => 'scheduled']);
    }

    // ─── Sending ─────────────────────────────────────────────────────

    public function test_send_now_delivers_to_the_audience_and_records_counts(): void
    {
        // Three freelancers with distinct emails — the resolved audience.
        Freelancer::factory()->create(['email' => 'f1@example.com']);
        Freelancer::factory()->create(['email' => 'f2@example.com']);
        Freelancer::factory()->create(['email' => 'f3@example.com']);
        // An employer that must NOT be included for a 'freelancers' send.
        Employer::factory()->create(['email' => 'boss@example.com']);

        $res = $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'Hello freelancers',
            'body' => '<p>Thanks for being part of ForgeKin.</p>',
            'audience' => 'freelancers',
            'action' => 'send',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'sent');

        $id = $res->json('data.id');
        $campaign = EmailCampaign::find($id);
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(3, $campaign->total_recipients);
        $this->assertSame(3, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        $this->assertNotNull($campaign->completed_at);
    }

    public function test_send_now_also_delivers_an_in_app_notification(): void
    {
        $f1 = Freelancer::factory()->create(['email' => 'inapp1@example.com']);
        $f2 = Freelancer::factory()->create(['email' => 'inapp2@example.com']);
        // An employer outside the 'freelancers' audience — must NOT be notified.
        Employer::factory()->create(['email' => 'skip@example.com']);

        $res = $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'Big announcement',
            'body' => '<p>We just shipped something <strong>great</strong>.</p>',
            'audience' => 'freelancers',
            'action' => 'send',
        ]);

        $res->assertStatus(201)->assertJsonPath('data.status', 'sent');

        // One database notification per freelancer recipient, none for the employer.
        $this->assertSame(2, \DB::table('notifications')->count());

        foreach ([$f1, $f2] as $f) {
            $this->assertSame(1, $f->notifications()->count());
            $data = $f->notifications()->first()->data;
            $this->assertSame('broadcast', $data['type']);
            $this->assertSame('Big announcement', $data['title']);
            $this->assertStringContainsString('shipped something', $data['body']);
        }
    }

    public function test_in_app_notification_surfaces_in_the_notifications_api(): void
    {
        // The sending admin is a system user, so a broadcast to 'system_users'
        // lands in their own notification feed — readable via /api/notifications.
        $this->auth()->postJson('/api/admin/campaigns', [
            'subject' => 'Team update',
            'body' => '<p>Hello team.</p>',
            'audience' => 'system_users',
            'action' => 'send',
        ])->assertStatus(201);

        $res = $this->auth()->getJson('/api/notifications');

        $res->assertStatus(200)
            ->assertJsonPath('data.data.0.type', 'broadcast')
            ->assertJsonPath('data.data.0.title', 'Team update');

        $this->assertStringContainsString('Hello team', $res->json('data.data.0.body'));
    }

    public function test_send_existing_draft(): void
    {
        Freelancer::factory()->create(['email' => 'solo@example.com']);

        $campaign = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Draft to send',
            'body' => '<p>Body.</p>',
            'audience' => 'freelancers',
            'status' => 'draft',
        ]);

        $this->auth()->postJson("/api/admin/campaigns/{$campaign->id}/send")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'sent');

        $this->assertSame(1, $campaign->fresh()->sent_count);
    }

    public function test_run_due_processes_scheduled_campaigns_whose_time_passed(): void
    {
        Freelancer::factory()->create(['email' => 'due1@example.com']);
        Freelancer::factory()->create(['email' => 'due2@example.com']);

        // Past-due scheduled campaign (created directly to bypass future-date rule).
        $due = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Due now',
            'body' => '<p>Body.</p>',
            'audience' => 'freelancers',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(5),
        ]);

        // A future one that must be left alone.
        $future = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Later',
            'body' => '<p>Body.</p>',
            'audience' => 'freelancers',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(3),
        ]);

        $this->auth()->postJson('/api/admin/campaigns/run-due')
            ->assertStatus(200)
            ->assertJsonPath('data.processed', 1);

        $this->assertSame('sent', $due->fresh()->status);
        $this->assertSame(2, $due->fresh()->sent_count);
        $this->assertSame('scheduled', $future->fresh()->status);
    }

    public function test_auto_tick_sends_due_campaigns_without_cron(): void
    {
        Cache::flush();
        Freelancer::factory()->create(['email' => 'tick1@example.com']);

        $due = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Auto', 'body' => '<p>Body.</p>',
            'audience' => 'freelancers', 'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        CampaignDispatcher::tick();

        $this->assertSame('sent', $due->fresh()->status);
        $this->assertSame(1, $due->fresh()->sent_count);
    }

    public function test_auto_tick_is_throttled_to_once_per_window(): void
    {
        Cache::flush();
        Freelancer::factory()->create(['email' => 'throttle@example.com']);

        // First tick claims the lock and processes nothing-yet-due gracefully.
        CampaignDispatcher::tick();

        // A campaign becomes due, but a second tick within the same window is
        // locked out, so it must NOT be picked up yet.
        $late = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Late', 'body' => '<p>Body.</p>',
            'audience' => 'freelancers', 'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        CampaignDispatcher::tick();

        $this->assertSame('scheduled', $late->fresh()->status);
    }

    // ─── Editing & deleting ──────────────────────────────────────────

    public function test_can_update_a_draft(): void
    {
        $campaign = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Old subject', 'body' => '<p>Old.</p>',
            'audience' => 'everyone', 'status' => 'draft',
        ]);

        $this->auth()->putJson("/api/admin/campaigns/{$campaign->id}", [
            'subject' => 'New subject', 'body' => '<p>New body content.</p>',
            'audience' => 'employers', 'action' => 'draft',
        ])->assertStatus(201)->assertJsonPath('data.subject', 'New subject');

        $this->assertDatabaseHas('email_campaigns', ['id' => $campaign->id, 'subject' => 'New subject', 'audience' => 'employers']);
    }

    public function test_cannot_edit_a_sent_campaign(): void
    {
        $campaign = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Done', 'body' => '<p>Sent.</p>',
            'audience' => 'everyone', 'status' => 'sent',
        ]);

        $this->auth()->putJson("/api/admin/campaigns/{$campaign->id}", [
            'subject' => 'Nope', 'body' => '<p>Trying to edit.</p>',
            'audience' => 'everyone', 'action' => 'draft',
        ])->assertStatus(422);
    }

    public function test_can_delete_a_draft(): void
    {
        $campaign = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'Trash me', 'body' => '<p>Body.</p>',
            'audience' => 'everyone', 'status' => 'draft',
        ]);

        $this->auth()->deleteJson("/api/admin/campaigns/{$campaign->id}")
            ->assertStatus(200)->assertJson(['success' => true]);

        $this->assertDatabaseMissing('email_campaigns', ['id' => $campaign->id]);
    }

    public function test_cannot_delete_a_sending_campaign(): void
    {
        $campaign = EmailCampaign::create([
            'created_by' => $this->admin->id,
            'subject' => 'In flight', 'body' => '<p>Body.</p>',
            'audience' => 'everyone', 'status' => 'sending',
        ]);

        $this->auth()->deleteJson("/api/admin/campaigns/{$campaign->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('email_campaigns', ['id' => $campaign->id]);
    }
}
