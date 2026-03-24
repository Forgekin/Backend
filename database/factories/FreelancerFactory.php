<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Freelancer>
 */
class FreelancerFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'other_names' => fake()->optional()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'contact' => fake()->numerify('###########'),
            'password' => static::$password ??= Hash::make('Password1!'),
            'gender' => fake()->randomElement(['male', 'female', 'other']),
            'dob' => fake()->date('Y-m-d', '-18 years'),
            'proficiency' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'verification_code' => Str::random(6),
            'verification_code_expires_at' => now()->addMinutes(30),
            'email_verified_at' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_code_expires_at' => null,
        ]);
    }
}
