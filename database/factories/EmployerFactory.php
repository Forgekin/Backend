<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Employer>
 */
class EmployerFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'company_name' => fake()->unique()->company(),
            'contact' => fake()->numerify('###########'),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('Password1!'),
            'business_type' => fake()->randomElement(['Startup', 'SME', 'Corporation']),
            'verification_status' => 'inactive',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'verification_status' => 'active',
        ]);
    }
}
