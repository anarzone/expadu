<?php

namespace Database\Factories;

use App\Models\SlotMonitor;
use App\Models\User;
use App\Services\BuergeramtService;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlotMonitor> */
class SlotMonitorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'office_id' => fake()->randomElement(array_keys(BuergeramtService::OFFICES)),
            'service_type' => 'anmeldung',
            'is_active' => true,
            'notified_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
