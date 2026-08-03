<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'situation' => ['non_eu_employee', 'eu_employee'],
            'phase' => 'first_weeks',
            'deadline_type' => 'days_since_arrival',
            'deadline_days' => fake()->randomElement([14, 30, 60, 90]),
            'urgency' => fake()->randomElement(['critical', 'high', 'medium', 'low']),
            'links' => null,
            'documents_required' => null,
            'review_status' => 'legacy',
            'coverage_scope' => 'case',
        ];
    }
}
