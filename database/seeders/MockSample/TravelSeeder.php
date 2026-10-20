<?php

namespace Database\Seeders\MockSample;

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
 * the 13:24 as the Blue Card appointment's planned way, as the mock shows. The day's plans come from `mock:sample-day` (session 1)
 * and the sample's ways from App\Transit\MockSample\SampleRoutes while the
 * mock clock is on. Local only; never runs anywhere else.
 *
 * E2E_EMAIL=s3-compare@expadu.test php artisan db:seed --class="Database\Seeders\MockSample\TravelSeeder"
 * (each session compares as its own account; the default is the E2E one).
 * No trip is running afterwards; MOCK_TRIP=started starts the sample's 13:14
 * (the trip page's started moments, travel-trip-*.json).
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

        // The sample's planned way: the 13:24 tram 4 to the Blue Card
        // appointment ("Your 13:24 has only 2 min spare"; "In your plan").
        $planned = SampleRoutes::ways(self::DATE)[1];
        PlannedTrip::query()->updateOrCreate(
            ['user_id' => $user->id, 'date' => self::DATE, 'target_id' => 'mock:blue-card-appointment'],
            [
                'title' => 'Blue Card appointment',
                'journey' => $planned->toArray(),
                'depart_at' => $planned->departAt,
                'arrive_at' => $planned->arriveAt,
                'remind' => false,
                'reminded_at' => null,
            ],
        );

        // The trip page: nothing running, or (MOCK_TRIP=started) the sample's
        // 13:14 to the Blue Card appointment, started at 13:10.
        ActiveTrip::query()->where('user_id', $user->id)->delete();
        if (env('MOCK_TRIP') === 'started') {
            $way = SampleRoutes::ways(self::DATE)[0];
            $end = $way->legs[array_key_last($way->legs)]->to->point;
            ActiveTrip::query()->create([
                'user_id' => $user->id,
                'journey' => $way->toArray(),
                'destination' => ['name' => 'Blue Card appointment', 'lat' => $end->lat, 'lng' => $end->lng],
                'origin' => null,
                'target' => [
                    'id' => 'mock:blue-card-appointment', 'title' => 'Blue Card appointment', 'label' => 'Mülheim',
                    'kind' => 'appointment', 'official' => true, 'start_at' => self::DATE.'T14:00:00+02:00',
                ],
                'started_at' => self::DATE.' 13:10:00+02:00',
            ]);
        }
    }
}
