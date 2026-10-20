<?php

namespace Database\Seeders\MockSample;

use App\Models\FavouriteStop;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Redis;

/**
 * Travel's pieces of the mock's sample day (prototype/dev/design/connected/
 * sample-day.js), for tools/mock-compare: the E2E account stands at
 * Neumarkt ("From · near Neumarkt") with Neumarkt as a favourite stop, as
 * the mock shows. The day's plans come from `mock:sample-day` (session 1)
 * and the sample's ways from App\Transit\MockSample\SampleRoutes while the
 * mock clock is on. Local only; never runs anywhere else.
 *
 * E2E_EMAIL=s3-compare@expadu.test php artisan db:seed --class="Database\Seeders\MockSample\TravelSeeder"
 * (each session compares as its own account; the default is the E2E one).
 */
class TravelSeeder extends Seeder
{
    /** Neumarkt, where the sample day starts. */
    public const LAT = 50.9361;

    public const LNG = 6.9473;

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
    }
}
