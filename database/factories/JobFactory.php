<?php

namespace Database\Factories;

use App\Models\Employer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Job>
 */
class JobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employer_id' => Employer::factory(),
            'title' => fake()->jobTitle(),
            'description' => fake()->paragraph(3),
            'skills' => implode(', ', fake()->randomElements(['PHP', 'Laravel', 'React', 'Vue', 'Docker', 'MySQL', 'Redis'], 3)),
            'rate_type' => fake()->randomElement(['hourly', 'fixed']),
            'experience_level' => fake()->randomElement(['beginner', 'intermediate', 'advanced']),
            'min_budget' => fake()->numberBetween(10, 50),
            'max_budget' => fake()->numberBetween(51, 200),
            'deadline' => fake()->dateTimeBetween('+1 week', '+3 months')->format('Y-m-d'),
            'estimated_duration' => fake()->randomElement(['1 week', '2 weeks', '1 month', '3 months']),
            'shift_type' => fake()->randomElement(['Morning', 'Afternoon', 'Night', 'Any Shift']),
            'status' => 'new',
        ];
    }
}
