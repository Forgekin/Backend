<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Support replies are emailed to the recipient AND mirrored into their in-app
 * notification center — but only to a ForgeKin account that actually owns that
 * email, and only by support staff. These guards pin that behaviour down.
 */
class SupportReplyInAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Super-Admin']);
        Role::create(['name' => 'Admin']);
    }

    private function adminToken(): string
    {
        $u = User::factory()->create();
        $u->assignRole('Super-Admin');

        return $u->createToken('test')->plainTextToken;
    }

    public function test_contact_reply_is_mirrored_in_app_to_matching_freelancer(): void
    {
        $freelancer = Freelancer::factory()->create(['email' => 'kofi@example.com']);
        $contact = ContactMessage::create([
            'name' => 'Kofi',
            'email' => 'kofi@example.com',
            'subject' => 'Help with my job',
            'message' => 'I need assistance please.',
            'email_sent' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson("/api/admin/contact-messages/{$contact->id}/reply", ['message' => 'Here is how to fix it.'])
            ->assertStatus(200);

        $this->assertSame(1, $freelancer->notifications()->count());
        $data = $freelancer->notifications()->first()->data;
        $this->assertSame('support_reply', $data['type']);
        $this->assertSame('Here is how to fix it.', $data['message']);
        $this->assertSame('ForgeKin Support', $data['from']);
    }

    public function test_contact_reply_for_unknown_email_creates_no_in_app_notification(): void
    {
        // A freelancer exists, but with a DIFFERENT email than the contact.
        $freelancer = Freelancer::factory()->create(['email' => 'someone@example.com']);
        $contact = ContactMessage::create([
            'name' => 'Ama',
            'email' => 'stranger@example.com',
            'subject' => 'Question',
            'message' => 'Just a general question.',
            'email_sent' => false,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson("/api/admin/contact-messages/{$contact->id}/reply", ['message' => 'Thanks for reaching out.'])
            ->assertStatus(200);

        $this->assertSame(0, $freelancer->notifications()->count());
    }

    public function test_support_reply_is_mirrored_in_app_to_matching_employer(): void
    {
        $employer = Employer::factory()->create(['email' => 'biz@example.com']);

        $this->withHeader('Authorization', 'Bearer ' . $this->adminToken())
            ->postJson('/api/admin/support-reply', [
                'email' => 'biz@example.com',
                'subject' => 'Your support request',
                'message' => 'We have resolved your issue.',
            ])->assertStatus(200);

        $this->assertSame(1, $employer->notifications()->count());
        $this->assertSame('support_reply', $employer->notifications()->first()->data['type']);
        $this->assertSame('We have resolved your issue.', $employer->notifications()->first()->data['message']);
    }

    public function test_support_reply_is_restricted_to_staff(): void
    {
        $freelancer = Freelancer::factory()->create();
        Sanctum::actingAs($freelancer);

        $this->postJson('/api/admin/support-reply', [
            'email' => 'biz@example.com',
            'message' => 'I should not be allowed to send this.',
        ])->assertStatus(403);
    }

    public function test_contact_reply_is_restricted_to_staff(): void
    {
        $employer = Employer::factory()->active()->create();
        $contact = ContactMessage::create([
            'name' => 'Kofi', 'email' => 'kofi@example.com', 'subject' => 'Hi', 'message' => 'Hello there.', 'email_sent' => false,
        ]);

        Sanctum::actingAs($employer);

        $this->postJson("/api/admin/contact-messages/{$contact->id}/reply", ['message' => 'Trying to reply as a non-admin.'])
            ->assertStatus(403);
    }
}
