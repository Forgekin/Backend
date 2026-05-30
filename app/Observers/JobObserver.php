<?php

namespace App\Observers;

use App\Models\Job;
use App\Notifications\EmployerJobStatusUpdated;
use Illuminate\Support\Facades\Log;

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
    ];

    /**
     * Notify the employer whenever their job moves to a notable status,
     * regardless of which code path triggered the change.
     */
    public function updated(Job $job): void
    {
        if (!$job->wasChanged('status')) {
            return;
        }

        if (!in_array($job->status, $this->notifiableStatuses, true)) {
            return;
        }

        $employer = $job->employer;

        if (!$employer) {
            return;
        }

        try {
            $employer->notify(new EmployerJobStatusUpdated(
                $job->loadMissing('assignedFreelancer'),
                $job->getOriginal('status'),
            ));
        } catch (\Throwable $e) {
            // Never let a mail failure break the request that changed the job.
            Log::error('Employer job-status email failed', [
                'job_id' => $job->id,
                'employer_id' => $employer->id ?? null,
                'status' => $job->status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
