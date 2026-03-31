<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Routine;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPlace;
use App\Models\UserTask;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            TaskSeeder::class,
        ]);

        // System user for auto-generated content
        User::factory()->onboarded()->create([
            'name' => 'Expadu',
            'email' => 'system@expadu.com',
            'city' => 'cologne',
            'situation' => 'other',
        ]);

        $user = User::factory()->onboarded()->create([
            'name' => 'Anar',
            'email' => 'test@example.com',
            'city' => 'cologne',
            'situation' => 'non_eu_employee',
            'arrival_date' => now()->subDays(18),
            'german_level' => 'a2',
            'speaks' => ['english', 'turkish', 'azerbaijani'],
        ]);

        // Seed user places
        UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home', 'day_mode' => 'all']);
        UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);
        UserPlace::create(['user_id' => $user->id, 'emoji' => '📚', 'name' => 'Course', 'address' => 'Uni Köln', 'lat' => 50.9280, 'lng' => 6.9286, 'sort_order' => 2]);
        UserPlace::create(['user_id' => $user->id, 'emoji' => '⭐', 'name' => 'Café Schmitz', 'address' => 'Ehrenfeld · 0.3 km', 'lat' => 50.9478, 'lng' => 6.9195, 'sort_order' => 3]);

        // Assign tasks to user (some completed, some pending)
        $tasks = Task::all();
        foreach ($tasks as $task) {
            $situations = $task->situation;
            if (in_array('non_eu_employee', $situations)) {
                $isCompleted = in_array($task->urgency->value, ['low']) || $task->title === 'Library Card';
                UserTask::create([
                    'user_id' => $user->id,
                    'task_id' => $task->id,
                    'completed_at' => $isCompleted ? now()->subDays(rand(1, 10)) : null,
                ]);
            }
        }

        // Seed an upcoming appointment
        Appointment::create([
            'user_id' => $user->id,
            'office_name' => 'Bürgeramt Deutz',
            'scheduled_at' => now()->addDay()->setTime(9, 15),
            'notes' => 'Bring Anmeldeformular, Passport, Rental contract',
        ]);

        // Seed a routine
        Routine::create([
            'user_id' => $user->id,
            'name' => 'Morning Commute',
            'emoji' => '🏠→💼',
            'from_stop' => 'Ehrenfeld',
            'to_stop' => 'Mediapark',
            'days' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'departure_time' => '08:15',
            'is_active' => true,
        ]);

        $this->call([
            SpotSeeder::class,
            EventSeeder::class,
            AlertSeeder::class,
        ]);
    }
}
