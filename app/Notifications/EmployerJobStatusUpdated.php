<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to the employer who owns a job whenever its status changes to a
 * notable state (assigned, in_progress, on_hold, done, approved).
 */
class EmployerJobStatusUpdated extends Notification
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

        $freelancerName = null;
        if ($this->job->assignedFreelancer) {
            $f = $this->job->assignedFreelancer;
            $freelancerName = trim(($f->first_name ?? '') . ' ' . ($f->last_name ?? '')) ?: null;
        }

        $headline = match ($status) {
            'assigned' => $freelancerName
                ? "{$freelancerName} has been assigned to your job."
                : 'A freelancer has been assigned to your job.',
            'in_progress' => 'Work has started — your job is now in progress.',
            'on_hold' => 'Your job has been placed on hold.',
            'done' => 'Your job has been marked as completed.',
            'approved' => 'Your job posting has been approved and is now live.',
            'rejected' => 'Unfortunately, your job posting was not approved.',
            default => "Your job status has been updated to \"{$this->humanStatus($status)}\".",
        };

        $message = (new MailMessage)
            ->subject("Update on your ForgeKin job: {$title}")
            ->greeting("Hello {$firstName},")
            ->line($headline)
            ->line('Job: ' . $title)
            ->line('Current status: ' . $this->humanStatus($status));

        if ($status === 'approved' && !is_null($this->job->agreed_rate)) {
            $message->line('Agreed rate: ' . number_format($this->job->agreed_rate, 2));
        }

        if ($status === 'rejected' && !empty($this->job->rejection_reason)) {
            $message->line('Reason: ' . $this->job->rejection_reason);
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
