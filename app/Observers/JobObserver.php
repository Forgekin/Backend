<?php

namespace App\Observers;

use App\Models\Job;
use App\Models\User;
use App\Notifications\AdminJobStatusUpdated;
use App\Notifications\EmployerJobStatusUpdated;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class JobObserver
{
    /**
     * Statuses worth emailing the job's employer about. Creation ("new") and
     * "pending_approval" are intentionally excluded — the employer just did
     * those themselves.
     *
     * @var list<string>
     */
    protected array $notifiableStatuses = [
        'assigned',
        'in_progress',
        'on_hold',
        'done',
        'approved',
        'rejected',
    ];

    /**
     * Notify the employer and the admin team whenever a job moves to a notable
     * status, regardless of which code path triggered the change.
     */
    public function updated(Job $job): void
    {
        if (!$job->wasChanged('status')) {
            return;
        }

        if (!in_array($job->status, $this->notifiableStatuses, true)) {
            return;
        }

        $job->loadMissing('assignedFreelancer', 'employer');
        $previousStatus = $job->getOriginal('status');

        // Confirmation to the job's employer.
        if ($job->employer) {
            try {
                $job->employer->notify(new EmployerJobStatusUpdated($job, $previousStatus));
            } catch (\Throwable $e) {
                // Never let a mail failure break the request that changed the job.
                Log::error('Employer job-status email failed', [
                    'job_id' => $job->id,
                    'employer_id' => $job->employer->id ?? null,
                    'status' => $job->status,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Visibility alert to all admins (Super-Admin / Admin).
        try {
            $admins = User::whereHas('roles', function ($q) {
                $q->whereIn('name', ['Super-Admin', 'Admin']);
            })->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new AdminJobStatusUpdated($job, $previousStatus));
            }
        } catch (\Throwable $e) {
            Log::error('Admin job-status email failed', [
                'job_id' => $job->id,
                'status' => $job->status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
