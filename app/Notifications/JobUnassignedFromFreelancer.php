<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobUnassignedFromFreelancer extends Notification
{
    use Queueable;

    protected $job;

    public function __construct(Job $job)
    {
        $this->job = $job;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';
        $employerName = $this->job->employer->company_name
            ?? trim(($this->job->employer->first_name ?? '') . ' ' . ($this->job->employer->last_name ?? ''))
            ?: 'an employer';

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('A job assignment has been removed on ForgeKin')
            ->greeting("Hello {$firstName},")
            ->line("We're letting you know that {$employerName} has unassigned you from the following job.")
            ->line('Job: ' . $this->job->title)
            ->line('This job is now open again and you are no longer responsible for it.')
            ->action('View job', $frontend . '/jobs/' . $this->job->id)
            ->line('If you have any questions, please reach out to the employer or the ForgeKin team.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_unassigned',
            'title' => 'Job assignment removed',
            'message' => 'You have been unassigned from "' . $this->job->title . '". This job is now open again.',
            'url' => '/jobs/' . $this->job->id,
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'employer_id' => $this->job->employer_id,
        ];
    }
}
