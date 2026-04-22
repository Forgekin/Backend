<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FreelancerWithdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'freelancer_id',
        'amount',
        'currency',
        'method',
        'destination',
        'status',
        'reference',
        'requested_at',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'requested_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function freelancer()
    {
        return $this->belongsTo(Freelancer::class);
    }
}
