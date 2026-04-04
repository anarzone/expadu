<?php

namespace Database\Seeders;

use App\Models\User;
use App\Notifications\GenericAlertNotification;
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

        // System: Bürgeramt (use generic since we don't have real slot data)
        $user->notify(new GenericAlertNotification(
            'Appointment slot available — Bürgeramt Deutz',
            'A slot opened for tomorrow at 09:15. You have 30 minutes to book before it\'s taken.',
            '/bureaucracy',
        ));

        // System: Rhine level
        $user->notify(new RhineFloodNotification(645.0, 'high', 'rising'));

        // System: Weather
        $user->notify(new WeatherAlertNotification(
            'Rain this afternoon',
            'Rain expected from 14:00. Consider transit instead of cycling.',
        ));

        // Reminder: Event (use generic since we may not have events with attendees)
        $user->notify(new GenericAlertNotification(
            'Tomorrow: International Expat Mixer',
            'You\'re going to the International Expat Mixer at Startplatz. Starts at 18:00. 32 people attending.',
            '/events',
        ));

        // Reminder: Bureaucracy
        $user->notify(new GenericAlertNotification(
            'Reminder: Bank account not yet opened',
            'Opening a German bank account is step 3 in your settlement. Most expats use N26 or Commerzbank.',
            '/bureaucracy',
        ));

        // Social: Language partner (use generic)
        $user->notify(new GenericAlertNotification(
            'New language partner match — Anna B.',
            'Anna speaks German (native) and wants to practice English. You\'re a great match!',
            '/language-exchange',
        ));

        $this->command->info('Seeded alerts via real notification system.');
    }
}
