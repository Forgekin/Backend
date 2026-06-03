<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Freelancer;
use App\Models\Job;
use App\Models\User;
use App\Notifications\AccountDeactivated;
use App\Notifications\AccountReactivated;
use App\Notifications\AdminJobStatusUpdated;
use App\Notifications\CampaignBroadcast;
use App\Notifications\EmployerApproved;
use App\Notifications\EmployerJobStatusUpdated;
use App\Notifications\EmployerRegistered;
use App\Notifications\EmployerVerificationRevoked;
use App\Notifications\FreelancerAcceptedJob;
use App\Notifications\FreelancerJobStatusUpdated;
use App\Notifications\JobAssignedToFreelancer;
use App\Notifications\JobPosted;
use App\Notifications\JobUnassignedFromFreelancer;
use App\Notifications\NewEmployerRegistered;
use App\Notifications\NewFreelancerRegistered;
use App\Notifications\NewJobPosted;
use App\Notifications\SupportReplyReceived;
use App\Notifications\SupportRequestSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * One contract test covering EVERY notification the platform sends. For each it
 * verifies: the delivery channels are exactly what we intend (mail + database,
 * or database-only), the in-app payload is well-formed (non-empty type, title
 * and message — so it renders consistently with the email), and the email
 * representation builds with a subject. This guarantees no notification is
 * silently mis-channelled, mis-formatted, or broken.
 */
class NotificationMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const MAIL_DB = ['mail', 'database'];
    private const DB_ONLY = ['database'];

    public function test_every_notification_has_correct_channels_payload_and_email(): void
    {
        $employer = Employer::factory()->create();
        $freelancer = Freelancer::factory()->create();
        $admin = User::factory()->create();
        $job = Job::factory()->create([
            'employer_id' => $employer->id,
            'assigned_freelancer_id' => $freelancer->id,
        ]);

        // [label, notification, notifiable (recipient), expected channels]
        $cases = [
            // → Employer
            ['JobPosted', new JobPosted($job), $employer, self::MAIL_DB],
            ['EmployerApproved', new EmployerApproved($employer), $employer, self::MAIL_DB],
            ['EmployerRegistered', new EmployerRegistered($employer), $employer, self::MAIL_DB],
            ['EmployerVerificationRevoked', new EmployerVerificationRevoked($employer), $employer, self::MAIL_DB],
            ['EmployerJobStatusUpdated', new EmployerJobStatusUpdated($job, 'assigned'), $employer, self::MAIL_DB],

            // → Freelancer
            ['JobAssignedToFreelancer', new JobAssignedToFreelancer($job), $freelancer, self::MAIL_DB],
            ['JobUnassignedFromFreelancer', new JobUnassignedFromFreelancer($job), $freelancer, self::MAIL_DB],
            ['FreelancerJobStatusUpdated', new FreelancerJobStatusUpdated($job, 'assigned'), $freelancer, self::MAIL_DB],
            ['AccountDeactivated', new AccountDeactivated(), $freelancer, self::MAIL_DB],
            ['AccountReactivated', new AccountReactivated(), $freelancer, self::MAIL_DB],
            ['SupportReplyReceived', new SupportReplyReceived('Re: Your request', 'Here is our reply.'), $freelancer, self::DB_ONLY],
            ['CampaignBroadcast', new CampaignBroadcast(1, 'Newsletter', '<p>Hello <b>world</b></p>'), $freelancer, self::DB_ONLY],

            // → Super-Admin / Admin
            ['NewJobPosted', new NewJobPosted($job), $admin, self::MAIL_DB],
            ['NewEmployerRegistered', new NewEmployerRegistered($employer), $admin, self::MAIL_DB],
            ['NewFreelancerRegistered', new NewFreelancerRegistered($freelancer), $admin, self::MAIL_DB],
            ['AdminJobStatusUpdated', new AdminJobStatusUpdated($job, 'assigned', 'Admin User'), $admin, self::MAIL_DB],
            ['FreelancerAcceptedJob', new FreelancerAcceptedJob($job), $admin, self::MAIL_DB],
            ['SupportRequestSubmitted', new SupportRequestSubmitted('Help', 'I need help with a job.', 'Kofi', 'kofi@example.com', 1), $admin, self::MAIL_DB],
        ];

        foreach ($cases as [$label, $notification, $notifiable, $expectedChannels]) {
            // 1. Channels are exactly as intended (targeting + cross-channel consistency).
            $this->assertEqualsCanonicalizing(
                $expectedChannels,
                $notification->via($notifiable),
                "{$label}: unexpected delivery channels"
            );

            // 2. In-app payload is well-formed (renders consistently in the bell/center).
            $data = $notification->toArray($notifiable);
            $this->assertIsString($data['type'] ?? null, "{$label}: missing 'type'");
            $this->assertNotEmpty($data['type'], "{$label}: empty 'type'");
            $this->assertNotEmpty($data['title'] ?? null, "{$label}: empty 'title'");
            $this->assertNotEmpty($data['message'] ?? null, "{$label}: empty 'message'");

            // 3. Email representation builds with a subject (delivery + format).
            if (in_array('mail', $expectedChannels, true)) {
                $mail = $notification->toMail($notifiable);
                $this->assertInstanceOf(MailMessage::class, $mail, "{$label}: toMail did not return a MailMessage");
                $this->assertNotEmpty($mail->subject, "{$label}: email has no subject");
            }
        }
    }

    public function test_database_only_notifications_never_send_email(): void
    {
        $freelancer = Freelancer::factory()->create();

        $this->assertNotContains('mail', (new CampaignBroadcast(1, 'x', '<p>y</p>'))->via($freelancer));
        $this->assertNotContains('mail', (new SupportReplyReceived('Re: x', 'y'))->via($freelancer));
    }
}
