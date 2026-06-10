<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Notifications\EventReminderNotification;
use App\Notifications\RhineFloodNotification;
use App\Notifications\TransitDelayNotification;
use App\Notifications\TransitDisruptionNotification;
use App\Notifications\WeatherAlertNotification;
use Illuminate\Database\Seeder;

/**
 * Seeds realistic alerts by sending real notifications through the system.
 * This triggers the CreateAlertFromNotification listener, creating proper Alert records.
 */
class AlertSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();
        if (! $user) {
            return;
        }

        // System: Transit disruption
        $user->notify(new TransitDisruptionNotification([
            'line' => 'Line 1',
            'summary' => 'Significant delays between Neumarkt and Deutz due to track maintenance. Expected resumption 18:30.',
        ]));

        // System: Transit delay
        $user->notify(new TransitDelayNotification('S12', 8, 'Ehrenfeld'));

        // System: Rhine level
        $user->notify(new RhineFloodNotification(645.0, 'high', 'rising'));

        // System: Weather
        $user->notify(new WeatherAlertNotification(
            'Rain this afternoon',
            'Rain expected from 14:00. Consider transit instead of cycling.',
        ));

        // Reminder: Bureaucracy deadline
        $user->notify(new BureaucracyDeadlineNotification(
            taskTitle: 'Register your address (Anmeldung)',
            tier: 'critical',
            daysRemaining: 3,
            deadline: now()->addDays(3)->toDateString(),
        ));

        // Reminder: Event (only when an event exists to point at)
        $event = Event::query()->where('starts_at', '>', now())->first();
        if ($event) {
            $user->notify(new EventReminderNotification($event));
        }

        $this->command->info('Seeded alerts via real notification system.');
    }
}
