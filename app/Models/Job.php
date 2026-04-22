<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Job extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'employer_id',
        'assigned_freelancer_id',
        'title',
        'description',
        'skills',
        'rate_type',
        'deadline',
        'experience_level',
        'min_budget',
        'max_budget',
        'estimated_duration',
        'shift_type',
        'status',
        'assigned_at',
        'actual_start_date',
        'completed_at',
        'agreed_rate',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'actual_start_date' => 'date',
        'completed_at' => 'datetime',
        'deadline' => 'date',
        'agreed_rate' => 'float',
        'min_budget' => 'float',
        'max_budget' => 'float',
    ];

    protected $table = 'job_postings';

    /**
     * The employer who posted the job.
     */
    public function employer()
    {
        return $this->belongsTo(Employer::class);
    }

    /**
     * The freelancer assigned to the job (if any).
     */
    public function assignedFreelancer()
    {
        return $this->belongsTo(Freelancer::class, 'assigned_freelancer_id');
    }

    public function hourLogs()
    {
        return $this->hasMany(JobHour::class);
    }

    public function payments()
    {
        return $this->hasMany(JobPayment::class);
    }

    public function review()
    {
        return $this->hasOne(JobReview::class);
    }
}
