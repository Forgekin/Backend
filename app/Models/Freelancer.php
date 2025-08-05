<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Laravel\Sanctum\HasApiTokens;

class Freelancer extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'fullname',
        'email',
        'contact',
        'password',
        'gender',
        'dob',
        'profession',
        'verification_code',
        'email_verified_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'verification_code'
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'dob' => 'date',
        'email_verified_at' => 'datetime',
    ];

    /**
     * Generate and save a verification code
     */
    public function generateVerificationCode(): string
    {
        $this->update([
            'verification_code' => Str::random(6), // or rand(100000, 999999)
            'email_verified_at' => null
        ]);

        return $this->verification_code;
    }

    /**
     * Verify the code and mark email as verified
     */
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

    /**
     * Check if email is verified
     */
    public function isVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Automatically hash passwords when setting
     */
    // public function setPasswordAttribute($value): void
    // {
    //     $this->attributes['password'] = Hash::make($value);
    // }

    /**
     * Get the route key for the model.
     */
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

}
