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
        'title',
        'description',
        'skills',
        'deadline',
        'shift_type',
        'budget_min',
        'budget_max',
        'status',
    ];

    protected $table = 'job_postings';

    /**
     * The employer who posted the job.
     */
    public function employer()
    {
        return $this->belongsTo(Employer::class);
    }
}
