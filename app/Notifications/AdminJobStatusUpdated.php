<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emailed to admins (Super-Admin / Admin) whenever a job's status changes,
 * so the team has visibility over the job lifecycle.
 */
class AdminJobStatusUpdated extends Notification
{
    use Queueable;

    public function __construct(
        protected Job $job,
        protected ?string $previousStatus = null,
        protected ?string $changedBy = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        // Mailed to admins AND stored in the database so it surfaces in the
        // admin Support & Notification Center.
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $notifiable->first_name ?? 'there';
        $title = $this->job->title;
        $status = $this->humanStatus($this->job->status);

        $employer = $this->job->employer;
        $employerName = $employer
            ? ($employer->company_name
                ?: (trim(($employer->first_name ?? '') . ' ' . ($employer->last_name ?? '')) ?: 'an employer'))
            : 'an employer';

        $freelancer = $this->job->assignedFreelancer;
        $freelancerName = $freelancer
            ? (trim(($freelancer->first_name ?? '') . ' ' . ($freelancer->last_name ?? '')) ?: 'Unnamed freelancer')
            : 'Not assigned';

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $message = (new MailMessage)
            ->subject("Job status updated: {$title}")
            ->greeting("Hello {$firstName},")
            ->line("A job's status has changed.")
            ->line('Job: ' . $title)
            ->line('Employer: ' . $employerName)
            ->line('Assigned freelancer: ' . $freelancerName)
            ->line('New status: ' . $status);

        if ($this->changedBy) {
            $message->line('Changed by: ' . $this->changedBy);
        }

        if ($this->job->status === 'rejected' && !empty($this->job->rejection_reason)) {
            $message->line('Reason: ' . $this->job->rejection_reason);
        }

        return $message
            ->action('Review job', $frontend . '/admin/jobs/' . $this->job->id)
            ->line('Open the admin dashboard for full details.');
    }

    public function toArray(object $notifiable): array
    {
        $status = $this->humanStatus($this->job->status);
        $message = '"' . $this->job->title . '" is now ' . $status . '.';
        if ($this->changedBy) {
            $message .= ' Changed by ' . $this->changedBy . '.';
        }

        
        return [
            // Keys the Notification Center renders directly.
            'type' => 'job_status_updated',
            'title' => 'Job status updated: ' . $this->job->title,
            'message' => $message,
            'url' => '/jobs?job=' . $this->job->id,
            // Structured context for any future use.
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'status' => $this->job->status,
            'previous_status' => $this->previousStatus,
            'employer_id' => $this->job->employer_id,
            'freelancer_id' => $this->job->assigned_freelancer_id,
            'changed_by' => $this->changedBy,
        ];
    }

    protected function humanStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }
}
