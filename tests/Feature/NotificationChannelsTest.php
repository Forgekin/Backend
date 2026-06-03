<?php

namespace Tests\Feature;

use App\Models\Employer;
use App\Models\Job;
use App\Models\User;
use App\Notifications\AccountDeactivated;
use App\Notifications\EmployerRegistered;
use App\Notifications\EmployerVerificationRevoked;
use App\Notifications\NewEmployerRegistered;
use App\Notifications\NewJobPosted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards that notifications which used to be email-only now also persist as
 * in-app (database) notifications, with a payload the client can render.
 */
class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_employer_registered_is_stored_in_app(): void
    {
        $employer = Employer::factory()->create();
        $employer->notify(new EmployerRegistered($employer));

        $this->assertSame(1, $employer->notifications()->count());
        $data = $employer->notifications()->first()->data;
        $this->assertSame('account', $data['type']);
        $this->assertSame('Registration received', $data['title']);
    }

    public function test_employer_verification_revoked_is_stored_in_app(): void
    {
        $employer = Employer::factory()->create();
        $employer->notify(new EmployerVerificationRevoked($employer));

        $this->assertSame(1, $employer->notifications()->count());
        $this->assertSame('Account verification revoked', $employer->notifications()->first()->data['title']);
    }

    public function test_account_deactivated_is_stored_in_app(): void
    {
        $user = User::factory()->create();
        $user->notify(new AccountDeactivated());

        $this->assertSame(1, $user->notifications()->count());
        $this->assertSame('account', $user->notifications()->first()->data['type']);
    }

    public function test_new_job_posted_is_stored_for_admin(): void
    {
        $employer = Employer::factory()->create();
        $job = Job::factory()->create(['employer_id' => $employer->id]);
        $admin = User::factory()->create();

        $admin->notify(new NewJobPosted($job));

        $data = $admin->notifications()->first()->data;
        $this->assertSame('job', $data['type']);
        $this->assertStringStartsWith('/jobs?job=', $data['url']);
    }

    public function test_new_employer_registered_is_stored_for_admin(): void
    {
        $employer = Employer::factory()->create();
        $admin = User::factory()->create();

        $admin->notify(new NewEmployerRegistered($employer));

        $data = $admin->notifications()->first()->data;
        $this->assertSame('employer', $data['type']);
        $this->assertSame('/users', $data['url']);
    }
}
