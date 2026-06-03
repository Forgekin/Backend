<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alert emailed to admins (Super-Admin / Admin) when a new job is posted so
 * they can review it.
 */
class NewJobPosted extends Notification
{
    use Queueable;

    public function __construct(protected Job $job)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';
        $employer = $this->job->employer;
        $employerName = $employer
            ? ($employer->company_name
                ?: (trim(($employer->first_name ?? '') . ' ' . ($employer->last_name ?? '')) ?: 'an employer'))
            : 'an employer';
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('New job posted on ForgeKin')
            ->greeting("Hello {$firstName},")
            ->line("A new job has been posted by {$employerName} and is awaiting review.")
            ->line('Job: ' . $this->job->title)
            ->action('Review job', $frontend . '/admin/jobs/' . $this->job->id)
            ->line('Please review it in the admin dashboard.');
    }

    public function toArray(object $notifiable): array
    {
        $employer = $this->job->employer;
        $employerName = $employer
            ? ($employer->company_name
                ?: (trim(($employer->first_name ?? '') . ' ' . ($employer->last_name ?? '')) ?: 'an employer'))
            : 'an employer';

        return [
            'type' => 'job',
            'title' => 'New job posted',
            'message' => 'A new job “' . $this->job->title . '” was posted by ' . $employerName . ' and is awaiting review.',
            'url' => '/jobs?job=' . $this->job->id,
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'employer_id' => $this->job->employer_id,
            'event' => 'new_job_posted',
        ];
    }
}
