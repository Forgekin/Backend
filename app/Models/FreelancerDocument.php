<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreelancerDocument extends Model
{
    protected $fillable = [
        'freelancer_id',
        'file_name',
        'file_url',
        'file_type',
    ];

    public function freelancer()
    {
        return $this->belongsTo(Freelancer::class);
    }
}
