<?php

namespace Database\Seeders\MockSample;

use App\Day\DayItem;
use App\Day\DayProjection;
use App\Models\ActiveTrip;
use App\Models\FavouriteStop;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Transit\MockSample\SampleRoutes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Redis;

/**
 * Travel's pieces of the mock's sample day (prototype/dev/design/connected/
 * sample-day.js), for tools/mock-compare: the E2E account stands at
 * Neumarkt ("From · near Neumarkt") with Neumarkt as a favourite stop and
 * the 13:24 as the EU Blue Card appointment's planned way, as the mock
 * shows. The appointment is the Paperwork sample's (MockSample\PaperworkSeeder,
 * "appointment:<its id>"), so seed that first; the rest of the day comes
 * from `mock:sample-day`, and the sample's ways from
 * App\Transit\MockSample\SampleRoutes while the mock clock is on. Local
 * only; never runs anywhere else.
 *
 * E2E_EMAIL=you@expadu.test php artisan db:seed --class="Database\Seeders\MockSample\PaperworkSeeder"
 * E2E_EMAIL=you@expadu.test php artisan db:seed --class="Database\Seeders\MockSample\TravelSeeder"
 * (each session compares as its own account; the default is the E2E one).
 * No trip is running afterwards; MOCK_TRIP=started starts the sample's 13:14
 * (the trip page's started moments, travel-trip-*.json). The account's
 * step-free need is off; MOCK_STEP_FREE=1 turns it on (travel-trip-lift-need
 * compares as the account's `+stepfree` sibling, seeded that way).
 */
class TravelSeeder extends Seeder
{
    /** Neumarkt, where the sample day starts. */
    public const LAT = 50.9361;

    public const LNG = 6.9473;

    /** The mock's sample day (connected/sample-day.js). */
    public const DATE = '2026-09-30';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        $email = (string) env('E2E_EMAIL', 'e2e@expadu.test');
        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->command?->warn("No account {$email}: run E2ETestUserSeeder first.");

            return;
        }

        // A confirmed location, kept for the comparison runs (the app's own
        // confirmations last two hours).
        Redis::setex("confirmed_location:{$user->id}", 30 * 86400, json_encode([
            'lat' => self::LAT,
            'lng' => self::LNG,
            'name' => 'Neumarkt',
            'at' => now()->timestamp,
        ], JSON_THROW_ON_ERROR));

        FavouriteStop::query()->firstOrCreate(['user_id' => $user->id, 'name' => 'Neumarkt']);

        // Off, as for anyone who hasn't said; on for the step-free comparison.
        $user->forceFill(['needs_step_free' => env('MOCK_STEP_FREE') === '1'])->save();

        // The day's 14:00 appointment, as the day projection reads it.
        $target = 'appointment:'.PaperworkSeeder::APPOINTMENT_ID;
        $appointment = collect(app(DayProjection::class)->itemsOn($user, self::DATE))
            ->first(fn (DayItem $item) => $item->id === $target);
        if ($appointment === null) {
            $this->command?->error("No 14:00 appointment on {$email}'s sample day: seed MockSample\\PaperworkSeeder first.");

            return;
        }

        // The sample's planned way: the 13:24 tram 4 to the EU Blue Card
        // appointment ("Your 13:24 has only 2 min spare"; "In your plan").
        $planned = SampleRoutes::ways(self::DATE)[1];
        PlannedTrip::query()->where('user_id', $user->id)->where('date', self::DATE)->delete();
        PlannedTrip::query()->updateOrCreate(
            ['user_id' => $user->id, 'date' => self::DATE, 'target_id' => $target],
            [
                'title' => $appointment->title,
                'journey' => $planned->toArray(),
                'depart_at' => $planned->departAt,
                'arrive_at' => $planned->arriveAt,
                'remind' => false,
                'reminded_at' => null,
            ],
        );

        // The trip page: nothing running, or (MOCK_TRIP=started) the sample's
        // 13:14 to the EU Blue Card appointment, started at 13:10.
        ActiveTrip::query()->where('user_id', $user->id)->delete();
        if (env('MOCK_TRIP') === 'started') {
            $way = SampleRoutes::ways(self::DATE)[0];
            $end = $way->legs[array_key_last($way->legs)]->to->point;
            ActiveTrip::query()->create([
                'user_id' => $user->id,
                'journey' => $way->toArray(),
                'destination' => ['name' => $appointment->title, 'lat' => $end->lat, 'lng' => $end->lng],
                'origin' => null,
                'target' => [
                    'id' => $target, 'title' => $appointment->title, 'label' => $appointment->locationLabel,
                    'kind' => $appointment->kind, 'official' => $appointment->official,
                    'start_at' => $appointment->startAt->setTimezone('Europe/Berlin')->toIso8601String(),
                ],
                'started_at' => self::DATE.' 13:10:00+02:00',
            ]);
        }
    }
}
