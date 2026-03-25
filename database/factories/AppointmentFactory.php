<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'office_name' => fake()->randomElement(['Bürgeramt Deutz', 'Bürgeramt Ehrenfeld', 'Bürgeramt Kalk', 'Ausländerbehörde Köln']),
            'scheduled_at' => fake()->dateTimeBetween('now', '+30 days'),
            'notes' => null,
            'reminder_sent_at' => null,
        ];
    }
}
