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

    protected $appends = ['file_url'];

    public function getFileUrlAttribute(): ?string
    {
        if (!$this->file_path) {
            return null;
        }
        $relative = ltrim(preg_replace('#^/?storage/#', '', $this->file_path), '/');
        return asset('storage/' . $relative);
    }

    public function freelancer()
    {
        return $this->belongsTo(Freelancer::class);
    }
}
