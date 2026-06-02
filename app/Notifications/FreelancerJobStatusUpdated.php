<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the assigned freelancer when an admin moves their job to a new
 * status (assigned, accepted, in_progress, on_hold, done).
 */
class FreelancerJobStatusUpdated extends Notification
{
    use Queueable;

    public function __construct(
        protected Job $job,
        protected ?string $previousStatus = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';
        $title = $this->job->title;
        $status = $this->job->status;

        $headline = match ($status) {
            'assigned' => 'You have been assigned to this job.',
            'accepted' => 'This job has been marked as accepted.',
            'in_progress' => 'Work on this job is now in progress.',
            'on_hold' => 'This job has been placed on hold.',
            'done' => 'This job has been marked as completed.',
            default => "This job's status has been updated to \"{$this->humanStatus($status)}\".",
        };

        $message = (new MailMessage)
            ->subject("Update on your ForgeKin job: {$title}")
            ->greeting("Hello {$firstName},")
            ->line($headline)
            ->line('Job: ' . $title)
            ->line('Current status: ' . $this->humanStatus($status));

        if (!is_null($this->job->freelancer_amount)) {
            $message->line("Amount you'll receive: GHS " . number_format($this->job->freelancer_amount, 2));
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $message->action('View job', $frontend . '/jobs/' . $this->job->id);

        return $message->line('Log in to ForgeKin to see the latest details.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_status',
            'title' => 'Job status updated',
            'message' => '"' . $this->job->title . '" is now ' . $this->humanStatus($this->job->status) . '.',
            'url' => '/jobs/' . $this->job->id,
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'status' => $this->job->status,
            'previous_status' => $this->previousStatus,
        ];
    }

    protected function humanStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
