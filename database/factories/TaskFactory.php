<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Task> */
class TaskFactory extends Factory
{
    /** Synthetic approval metadata for tests; does not review or publish catalogue content. */
    public function approvedFixture(): static
    {
        return $this->state(fn (): array => [
            'is_published' => true,
            'review_status' => 'approved',
            'jurisdiction' => 'de-nrw-cologne',
            'content_version' => 'synthetic-fixture.1',
            'reviewed_by' => 'synthetic_test_fixture',
            'source_verification' => 'dual_source',
            'verified_at' => today()->toDateString(),
            'review_due_at' => today()->addYear()->toDateString(),
            'legal_sources' => [
                ['kind' => 'primary', 'label' => 'Fixture statute', 'url' => 'https://www.gesetze-im-internet.de/bmg/__17.html'],
                ['kind' => 'implementation', 'label' => 'Fixture authority', 'url' => 'https://www.stadt-koeln.de/service/produkte/00415/index.html'],
            ],
        ]);
    }

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
