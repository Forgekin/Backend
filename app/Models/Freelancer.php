<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Freelancer extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

protected $fillable = [
    'first_name',
    'last_name',
    'other_names',
    'email',
    'contact',
    'password',
    'gender',
    'dob',
    'profile_image_url',
    'profession',
    'verification_code',
    'verification_code_expires_at',
    'email_verified_at'
];



    protected $hidden = [
        'password',
        'remember_token',
        'verification_code'
    ];

    protected $casts = [
        'dob' => 'date',
        'email_verified_at' => 'datetime',
    ];

    public function generateVerificationCode(): string
    {
        $code = Str::random(6);
        $this->update([
            'verification_code' => $code,
            'email_verified_at' => null
        ]);
        return $code;
    }

    public function verifyCode(string $code): bool
    {
        if ($this->verification_code === $code) {
            $this->update([
                'email_verified_at' => Carbon::now(),
                'verification_code' => null
            ]);
            return true;
        }
        return false;
    }

    public function isVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }

    // Relationships
    public function skills()
    {
        return $this->belongsToMany(Skill::class, 'freelancer_skill');
    }

    public function workExperiences()
    {
        return $this->hasMany(WorkExperience::class);
    }

    public function shifts()
    {
        return $this->belongsToMany(Shift::class, 'freelancer_shift');
    }

    public function documents()
    {
        return $this->hasMany(FreelancerDocument::class);
    }
}
