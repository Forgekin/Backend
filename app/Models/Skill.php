<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skill extends Model
{
    protected $fillable = ['name'];

    public function freelancers()
    {
        return $this->belongsToMany(Freelancer::class, 'freelancer_skill');
    }
}
