<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation emailed to the employer who owns a job when it is posted.
 */
class JobPosted extends Notification
{
    use Queueable;

    public function __construct(protected Job $job)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Your job has been posted on ForgeKin')
            ->greeting("Hello {$firstName},")
            ->line('Your job posting has been submitted and is now awaiting review by the ForgeKin team.')
            ->line('Job: ' . $this->job->title)
            ->action('View job', $frontend . '/jobs/' . $this->job->id)
            ->line("We'll let you know once it has been reviewed.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'event' => 'job_posted',
        ];
    }
}
