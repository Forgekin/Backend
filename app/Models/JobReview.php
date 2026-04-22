<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'employer_id',
        'freelancer_id',
        'stars',
        'review_text',
        'reviewed_at',
    ];

    protected $casts = [
        'stars' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function employer()
    {
        return $this->belongsTo(Employer::class);
    }

    public function freelancer()
    {
        return $this->belongsTo(Freelancer::class);
    }
}
