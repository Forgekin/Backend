<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreelancerDocument extends Model
{
    protected $fillable = [
        'freelancer_id',
        'file_path',
        'file_type',
        'original_name',
    ];

    public function freelancer()
    {
        return $this->belongsTo(Freelancer::class);
    }
}
