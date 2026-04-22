<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JobPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'employer_id',
        'freelancer_id',
        'gross',
        'platform_fee',
        'tax',
        'net',
        'currency',
        'status',
        'invoice_id',
        'paid_at',
    ];

    protected $casts = [
        'gross' => 'float',
        'platform_fee' => 'float',
        'tax' => 'float',
        'net' => 'float',
        'paid_at' => 'datetime',
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
