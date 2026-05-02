<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobAssignedToFreelancer extends Notification
{
    use Queueable;

    protected $job;

    public function __construct(Job $job)
    {
        $this->job = $job;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';
        $employerName = $this->job->employer->company_name
            ?? trim(($this->job->employer->first_name ?? '') . ' ' . ($this->job->employer->last_name ?? ''))
            ?: 'an employer';

        $message = (new MailMessage)
            ->subject('You have been assigned a new job on ForgeKin')
            ->greeting("Hello {$firstName},")
            ->line("Good news — {$employerName} has assigned you to a new job.")
            ->line('Job: ' . $this->job->title);

        if ($this->job->deadline) {
            $message->line('Deadline: ' . $this->job->deadline->toFormattedDateString());
        }

        if ($this->job->agreed_rate) {
            $message->line('Agreed rate: ' . number_format($this->job->agreed_rate, 2));
        }

        return $message
            ->action('View job', url('/jobs/' . $this->job->id))
            ->line('Log in to ForgeKin to review the details and get started.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'employer_id' => $this->job->employer_id,
        ];
    }
}
