<?php

namespace Database\Seeders;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\KvbApiService;
use App\Services\LocationPatternService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds 30 days of realistic tracking data for the test user.
 * Uses real spots, KVB stops, and saved places to generate patterns
 * that the recommendation engine can detect.
 *
 * Run: php artisan db:seed --class=TestTrackingDataSeeder
 */
class TestTrackingDataSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->first();
        if (! $user) {
            $this->command->error('Test user not found');

            return;
        }

        // Clear existing tracking data for clean patterns
        UserEvent::where('user_id', $user->id)->delete();
        $this->command->info('Cleared existing tracking data');

        $home = $user->places()->where('category', 'home')->first();
        $work = $user->places()->where('category', 'work')->first();
        $course = $user->places()->where('name', 'LIKE', '%Course%')->first();

        $homeLat = $home?->lat ?? 50.9478;
        $homeLng = $home?->lng ?? 6.9183;
        $workLat = $work?->lat ?? 50.9467;
        $workLng = $work?->lng ?? 6.9417;

        // Favorite café — real spot from DB
        $favCafe = Spot::where('name', 'ILIKE', '%Cafe Nova%')
            ->orWhere('name', 'ILIKE', '%Café Schmitz%')
            ->first();
        $favCafeName = $favCafe?->name ?? 'Café Nova';
        $favCafeLat = $favCafe?->lat ?? 50.9390;
        $favCafeLng = $favCafe?->lng ?? 6.9490;

        // Get real spots near home for realistic check-ins
        $nearbySpots = Spot::selectRaw('*, ABS(lat - ?) + ABS(lng - ?) as dist', [$homeLat, $homeLng])
            ->orderBy('dist')
            ->limit(5)
            ->get();

        // Get real KVB stops
        $kvb = app(KvbApiService::class);
        $allStops = $kvb->getStops();
        $nearHomeStops = collect($allStops)
            ->filter(fn ($s) => abs($s['lat'] - $homeLat) + abs($s['lng'] - $homeLng) < 0.02)
            ->take(3)
            ->values();
        $nearWorkStops = collect($allStops)
            ->filter(fn ($s) => abs($s['lat'] - $workLat) + abs($s['lng'] - $workLng) < 0.02)
            ->take(3)
            ->values();

        $events = [];
        $now = Carbon::now();

        // Simulate 30 days of behavior
        for ($day = 30; $day >= 0; $day--) {
            $date = $now->copy()->subDays($day);
            $isWeekday = $date->isWeekday();

            // ── Morning commute to work (weekdays 07:30-08:30) ──
            if ($isWeekday) {
                $departureTime = $date->copy()->setTime(7, rand(30, 50));

                // Location ping at home
                $events[] = $this->event($user->id, 'location_ping', [
                    'lat' => $homeLat + $this->jitter(),
                    'lng' => $homeLng + $this->jitter(),
                    'accuracy' => rand(10, 50),
                    'page' => 'dashboard',
                ], $departureTime->copy()->subMinutes(15));

                // Departure viewed at nearest stop
                if ($nearHomeStops->isNotEmpty()) {
                    $stop = $nearHomeStops->random();
                    $events[] = $this->event($user->id, 'departure_viewed', [
                        'stop_name' => $stop['name'],
                        'lat' => $homeLat + $this->jitter(),
                        'lng' => $homeLng + $this->jitter(),
                        'accuracy' => rand(10, 30),
                    ], $departureTime);
                }

                // Journey planned to work
                $events[] = $this->event($user->id, 'journey_planned', [
                    'from' => $home?->name ?? 'Home',
                    'to' => $work?->name ?? 'Work',
                    'from_lat' => $homeLat,
                    'from_lng' => $homeLng,
                    'to_lat' => $workLat,
                    'to_lng' => $workLng,
                    'dow' => $date->dayOfWeek,
                    'hour' => $departureTime->hour,
                ], $departureTime);

                // Location ping at work (arrived)
                $events[] = $this->event($user->id, 'location_ping', [
                    'lat' => $workLat + $this->jitter(),
                    'lng' => $workLng + $this->jitter(),
                    'accuracy' => rand(10, 50),
                    'page' => 'dashboard',
                ], $departureTime->copy()->addMinutes(25));
            }

            // ── Evening commute home (weekdays 17:00-18:00) ──
            if ($isWeekday) {
                $returnTime = $date->copy()->setTime(17, rand(0, 45));

                $events[] = $this->event($user->id, 'journey_planned', [
                    'from' => $work?->name ?? 'Work',
                    'to' => $home?->name ?? 'Home',
                    'from_lat' => $workLat,
                    'from_lng' => $workLng,
                    'to_lat' => $homeLat,
                    'to_lng' => $homeLng,
                    'dow' => $date->dayOfWeek,
                    'hour' => $returnTime->hour,
                ], $returnTime);

                if ($nearWorkStops->isNotEmpty()) {
                    $stop = $nearWorkStops->random();
                    $events[] = $this->event($user->id, 'departure_viewed', [
                        'stop_name' => $stop['name'],
                        'lat' => $workLat + $this->jitter(),
                        'lng' => $workLng + $this->jitter(),
                        'accuracy' => rand(10, 30),
                    ], $returnTime);
                }
            }

            // ── Tuesday/Thursday evening course (18:00) ──
            if ($course && in_array($date->dayOfWeek, [2, 4])) { // Tue, Thu
                $courseTime = $date->copy()->setTime(17, 45);
                $courseLat = $course->lat ?? 50.9280;
                $courseLng = $course->lng ?? 6.9286;

                $events[] = $this->event($user->id, 'journey_planned', [
                    'from' => $home?->name ?? 'Home',
                    'to' => $course->name,
                    'from_lat' => $homeLat,
                    'from_lng' => $homeLng,
                    'to_lat' => $courseLat,
                    'to_lng' => $courseLng,
                    'dow' => $date->dayOfWeek,
                    'hour' => $courseTime->hour,
                ], $courseTime);
            }

            // ── Daily morning café visit (08:15-08:45) — before work ──
            // Skip some days randomly for realism (go ~5 days/week)
            if ($favCafe && rand(1, 7) <= 5) {
                $cafeTime = $date->copy()->setTime(8, rand(15, 40));

                // Location ping near café
                $events[] = $this->event($user->id, 'location_ping', [
                    'lat' => $favCafeLat + $this->jitter(),
                    'lng' => $favCafeLng + $this->jitter(),
                    'accuracy' => rand(8, 25),
                    'page' => 'explore',
                ], $cafeTime);

                // View the spot
                $events[] = $this->event($user->id, 'spot_viewed', [
                    'spot_id' => $favCafe->id,
                    'spot_name' => $favCafeName,
                    'lat' => $favCafeLat,
                    'lng' => $favCafeLng,
                ], $cafeTime);

                // Auto proximity detection (from GPS)
                $events[] = $this->event($user->id, 'spot_proximity', [
                    'spot_id' => $favCafe->id,
                    'spot_name' => $favCafeName,
                    'lat' => $favCafeLat,
                    'lng' => $favCafeLng,
                    'distance_m' => rand(5, 50),
                ], $cafeTime->copy()->addMinutes(1));

                // Manual check in (sometimes)
                if (rand(1, 3) === 1) {
                    $events[] = $this->event($user->id, 'spot_checkin', [
                        'spot_id' => $favCafe->id,
                        'spot_name' => $favCafeName,
                    ], $cafeTime->copy()->addMinutes(2));
                }

                // Plan journey from café to work (weekdays)
                if ($isWeekday) {
                    $events[] = $this->event($user->id, 'journey_planned', [
                        'from' => $favCafeName,
                        'to' => $work?->name ?? 'Work',
                        'from_lat' => $favCafeLat,
                        'from_lng' => $favCafeLng,
                        'to_lat' => $workLat,
                        'to_lng' => $workLng,
                        'dow' => $date->dayOfWeek,
                        'hour' => $cafeTime->hour,
                    ], $cafeTime->copy()->addMinutes(30));
                }
            }

            // ── Saturday extra café + explore (10:00-11:00) ──
            if ($date->isSaturday() && $nearbySpots->isNotEmpty()) {
                $exploreTime = $date->copy()->setTime(10, rand(0, 30));
                $spot = $nearbySpots->random();

                $events[] = $this->event($user->id, 'spot_viewed', [
                    'spot_id' => $spot->id,
                    'spot_name' => $spot->name,
                    'lat' => $spot->lat,
                    'lng' => $spot->lng,
                ], $exploreTime);

                $events[] = $this->event($user->id, 'spot_checkin', [
                    'spot_id' => $spot->id,
                    'spot_name' => $spot->name,
                ], $exploreTime->copy()->addMinutes(5));
            }

            // ── Random page views throughout the day ──
            $pageViewCount = $isWeekday ? rand(3, 8) : rand(2, 5);
            $pages = ['/dashboard', '/transit', '/explore', '/events', '/bureaucracy'];
            for ($i = 0; $i < $pageViewCount; $i++) {
                $viewTime = $date->copy()->setTime(rand(7, 22), rand(0, 59));
                $events[] = $this->event($user->id, 'page_viewed', [
                    'page' => $pages[array_rand($pages)],
                ], $viewTime);
            }
        }

        // Bulk insert in chunks
        $chunks = array_chunk($events, 500);
        foreach ($chunks as $chunk) {
            UserEvent::insert($chunk);
        }

        $this->command->info('Seeded '.count($events).' tracking events over 30 days');

        // Show detected patterns
        $patternService = app(LocationPatternService::class);
        $patterns = $patternService->getRegularPatterns($user);
        $this->command->info('Detected '.count($patterns).' regular patterns:');
        foreach ($patterns as $p) {
            $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $this->command->info("  {$p['destination']} — {$days[$p['dow']]} {$p['hour']}:00 ({$p['frequency']}x, {$p['confidence']})");
        }

        $routines = $patternService->detectUnsavedRoutines($user);
        if ($routines) {
            $this->command->info('Unsaved routine suggestions:');
            foreach ($routines as $r) {
                $this->command->info("  {$r['suggestion']}");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function event(int $userId, string $type, array $payload, Carbon $at): array
    {
        return [
            'user_id' => $userId,
            'event_type' => $type,
            'payload' => json_encode($payload),
            'session_id' => 'seed_'.substr(md5($at->toDateString()), 0, 8),
            'created_at' => $at->toDateTimeString(),
        ];
    }

    /**
     * Small random offset to simulate GPS jitter.
     */
    private function jitter(): float
    {
        return rand(-50, 50) / 100000; // ~50m jitter
    }
}
