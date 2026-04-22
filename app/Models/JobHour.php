<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobHour extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'freelancer_id',
        'hours',
        'logged_for',
        'note',
    ];

    protected $casts = [
        'hours' => 'float',
        'logged_for' => 'date',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function freelancer()
    {
        return $this->belongsTo(Freelancer::class);
    }
}
