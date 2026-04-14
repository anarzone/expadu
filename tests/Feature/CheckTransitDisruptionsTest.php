<?php

use App\Models\Routine;
use App\Models\User;
use App\Models\UserPlace;
use App\Notifications\TransitDisruptionNotification;
use App\Services\DisruptionService;
use App\Services\NearbyStopService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Notification::fake();
    Cache::flush();

    // Travel to a unique date per test to avoid parallel dedup key collisions in Redis
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));

    // Seed minimal GTFS data: Ehrenfeld served by lines 3, 4; Deutz by lines 1, 7
    DB::table('gtfs_stops')->insert([
        ['stop_id' => 'S1', 'stop_name' => 'Ehrenfeld', 'stop_lat' => 50.94, 'stop_lng' => 6.92, 'location_type' => 0],
        ['stop_id' => 'S2', 'stop_name' => 'Deutz', 'stop_lat' => 50.94, 'stop_lng' => 6.97, 'location_type' => 0],
    ]);

    DB::table('gtfs_routes')->insert([
        ['route_id' => 'R3', 'route_short_name' => '3', 'route_type' => 0],
        ['route_id' => 'R4', 'route_short_name' => '4', 'route_type' => 0],
        ['route_id' => 'R1', 'route_short_name' => '1', 'route_type' => 0],
        ['route_id' => 'R7', 'route_short_name' => '7', 'route_type' => 0],
    ]);

    DB::table('gtfs_trips')->insert([
        ['trip_id' => 'T3', 'route_id' => 'R3', 'service_id' => 'SVC1'],
        ['trip_id' => 'T4', 'route_id' => 'R4', 'service_id' => 'SVC1'],
        ['trip_id' => 'T1', 'route_id' => 'R1', 'service_id' => 'SVC1'],
        ['trip_id' => 'T7', 'route_id' => 'R7', 'service_id' => 'SVC1'],
    ]);

    DB::table('gtfs_stop_times')->insert([
        ['trip_id' => 'T3', 'stop_id' => 'S1', 'stop_sequence' => 1, 'arrival_time' => '08:00:00', 'departure_time' => '08:00:00'],
        ['trip_id' => 'T4', 'stop_id' => 'S1', 'stop_sequence' => 1, 'arrival_time' => '08:05:00', 'departure_time' => '08:05:00'],
        ['trip_id' => 'T1', 'stop_id' => 'S2', 'stop_sequence' => 1, 'arrival_time' => '08:00:00', 'departure_time' => '08:00:00'],
        ['trip_id' => 'T7', 'stop_id' => 'S2', 'stop_sequence' => 1, 'arrival_time' => '08:10:00', 'departure_time' => '08:10:00'],
    ]);
});

/**
 * Clear throttle and dedup Redis keys for specific user IDs.
 * Required because parallel tests share Redis but user IDs recycle from transaction rollbacks.
 */
function clearThrottleFor(int ...$userIds): void
{
    try {
        $keys = [];
        foreach ($userIds as $id) {
            $keys[] = "notif_throttle:last:{$id}";
            $keys[] = "notif_throttle:hour:{$id}:".now()->format('Y-m-d-H');
            $keys[] = "notif_throttle:day:{$id}:".now()->format('Y-m-d');
            $keys[] = "user_transit_lines:{$id}";
        }
        Redis::del(...$keys);
    } catch (Throwable) {
    }
}

test('only notifies users whose routine stops are served by disrupted lines', function () {
    // Mock MUST be set up before creating routines so the artisan command gets it
    $this->mock(DisruptionService::class, function ($mock) {
        $mock->shouldReceive('getLineDisruptions')->andReturn([
            [
                'id' => 'news_99',
                'title' => 'Line 3 disruption',
                'description' => 'Construction on line 3',
                'severity' => 'major',
                'type' => 'line',
                'affected_lines' => ['3'],
                'started_at' => now()->toIso8601String(),
                'estimated_end' => null,
                'source' => 'kvb_news',
            ],
        ]);
    });

    $userEhrenfeld = User::factory()->onboarded()->create();
    Routine::factory()->create([
        'user_id' => $userEhrenfeld->id,
        'from_stop' => 'Ehrenfeld',
        'to_stop' => 'Neumarkt',
        'is_active' => true,
    ]);

    $userDeutz = User::factory()->onboarded()->create();
    Routine::factory()->create([
        'user_id' => $userDeutz->id,
        'from_stop' => 'Deutz',
        'to_stop' => 'Köln Messe',
        'is_active' => true,
    ]);

    clearThrottleFor($userEhrenfeld->id, $userDeutz->id);
    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertSentTo($userEhrenfeld, TransitDisruptionNotification::class);
    Notification::assertNotSentTo($userDeutz, TransitDisruptionNotification::class);
});

test('does not notify users with inactive routines', function () {
    $user = User::factory()->onboarded()->create();
    Routine::factory()->create([
        'user_id' => $user->id,
        'from_stop' => 'Ehrenfeld',
        'is_active' => false,
    ]);

    $this->mock(DisruptionService::class, function ($mock) {
        $mock->shouldReceive('getLineDisruptions')->andReturn([
            [
                'id' => 'news_100',
                'title' => 'Line 3 disruption',
                'description' => 'Test',
                'severity' => 'major',
                'type' => 'line',
                'affected_lines' => ['3'],
                'started_at' => now()->toIso8601String(),
                'estimated_end' => null,
                'source' => 'kvb_news',
            ],
        ]);
    });

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertNotSentTo($user, TransitDisruptionNotification::class);
});

test('notifies user when disruption affects their to_stop lines', function () {
    $user = User::factory()->onboarded()->create();
    Routine::factory()->create([
        'user_id' => $user->id,
        'from_stop' => 'Ehrenfeld',
        'to_stop' => 'Deutz',
        'is_active' => true,
    ]);

    // Line 7 serves Deutz (user's to_stop), not Ehrenfeld (user's from_stop)
    $this->mock(DisruptionService::class, function ($mock) {
        $mock->shouldReceive('getLineDisruptions')->andReturn([
            [
                'id' => 'news_101',
                'title' => 'Line 7 disruption',
                'description' => 'Test',
                'severity' => 'major',
                'type' => 'line',
                'affected_lines' => ['7'],
                'started_at' => now()->toIso8601String(),
                'estimated_end' => null,
                'source' => 'kvb_news',
            ],
        ]);
    });

    clearThrottleFor($user->id);
    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertSentTo($user, TransitDisruptionNotification::class);
});

// --- Place-based alerts ---

test('notifies user with saved place near disrupted line (no routine needed)', function () {
    $this->mock(NearbyStopService::class, function ($mock) {
        $mock->shouldReceive('getWalkableStops')->andReturn([
            ['id' => 1, 'name' => 'Merkenich Mitte', 'lines' => ['12', '140'], 'lat' => 51.02, 'lng' => 6.95, 'distance_m' => 250, 'walk_min' => 3],
        ]);
    });

    $this->mock(DisruptionService::class, function ($mock) {
        $mock->shouldReceive('getLineDisruptions')->andReturn([
            [
                'id' => 'news_200',
                'title' => 'Line 12 disruption',
                'description' => 'Construction on line 12',
                'severity' => 'major',
                'type' => 'line',
                'affected_lines' => ['12'],
                'started_at' => now()->toIso8601String(),
                'estimated_end' => null,
                'source' => 'kvb_news',
            ],
        ]);
    });

    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 51.023,
        'lng' => 6.950,
    ]);

    clearThrottleFor($user->id);
    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertSentTo($user, TransitDisruptionNotification::class);
});

test('does not notify user when saved place lines are not disrupted', function () {
    $this->mock(NearbyStopService::class, function ($mock) {
        $mock->shouldReceive('getWalkableStops')->andReturn([
            ['id' => 1, 'name' => 'Merkenich Mitte', 'lines' => ['12', '140'], 'lat' => 51.02, 'lng' => 6.95, 'distance_m' => 250, 'walk_min' => 3],
        ]);
    });

    $this->mock(DisruptionService::class, function ($mock) {
        $mock->shouldReceive('getLineDisruptions')->andReturn([
            [
                'id' => 'news_201',
                'title' => 'Line 3 disruption',
                'description' => 'Test',
                'severity' => 'major',
                'type' => 'line',
                'affected_lines' => ['3'],
                'started_at' => now()->toIso8601String(),
                'estimated_end' => null,
                'source' => 'kvb_news',
            ],
        ]);
    });

    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 51.023,
        'lng' => 6.950,
    ]);

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertNotSentTo($user, TransitDisruptionNotification::class);
});

// --- GPS-based alerts ---

test('notifies stationary user near disrupted line via GPS', function () {
    try {
        Redis::ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis not available');
    }

    $this->mock(NearbyStopService::class, function ($mock) {
        $mock->shouldReceive('getWalkableStops')->andReturn([
            ['id' => 1, 'name' => 'Neumarkt', 'lines' => ['1', '7', '9'], 'lat' => 50.94, 'lng' => 6.95, 'distance_m' => 100, 'walk_min' => 1],
        ]);
    });

    $this->mock(DisruptionService::class, function ($mock) {
        $mock->shouldReceive('getLineDisruptions')->andReturn([
            [
                'id' => 'news_202',
                'title' => 'Line 1 disruption',
                'description' => 'Test',
                'severity' => 'major',
                'type' => 'line',
                'affected_lines' => ['1'],
                'started_at' => now()->toIso8601String(),
                'estimated_end' => null,
                'source' => 'kvb_news',
            ],
        ]);
    });

    $user = User::factory()->onboarded()->create();

    // Simulate stationary GPS pings over 25 minutes (all at same spot)
    $key = "location_history:{$user->id}";
    for ($i = 0; $i < 5; $i++) {
        $ts = now()->subMinutes(25 - $i * 5)->timestamp;
        Redis::zadd($key, (string) $ts, json_encode([
            'lat' => 50.9390,
            'lng' => 6.9490,
            'accuracy' => 15,
            'at' => now()->subMinutes(25 - $i * 5)->toIso8601String(),
        ]));
    }

    // Flush the UserTransitLines cache so fresh data is used
    Cache::forget("user_transit_lines:{$user->id}");

    clearThrottleFor($user->id);
    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertSentTo($user, TransitDisruptionNotification::class);
});

test('does not notify moving user via GPS', function () {
    try {
        Redis::ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis not available');
    }

    $this->mock(NearbyStopService::class, function ($mock) {
        $mock->shouldReceive('getWalkableStops')->andReturn([
            ['id' => 1, 'name' => 'Neumarkt', 'lines' => ['1'], 'lat' => 50.94, 'lng' => 6.95, 'distance_m' => 100, 'walk_min' => 1],
        ]);
    });

    $this->mock(DisruptionService::class, function ($mock) {
        $mock->shouldReceive('getLineDisruptions')->andReturn([
            [
                'id' => 'news_203',
                'title' => 'Line 1 disruption',
                'description' => 'Test',
                'severity' => 'major',
                'type' => 'line',
                'affected_lines' => ['1'],
                'started_at' => now()->toIso8601String(),
                'estimated_end' => null,
                'source' => 'kvb_news',
            ],
        ]);
    });

    $user = User::factory()->onboarded()->create();

    // Simulate MOVING GPS pings — spread across 500m
    $key = "location_history:{$user->id}";
    for ($i = 0; $i < 5; $i++) {
        $ts = now()->subMinutes(25 - $i * 5)->timestamp;
        Redis::zadd($key, $ts, json_encode([
            'lat' => 50.9390 + $i * 0.002,
            'lng' => 6.9490,
            'accuracy' => 15,
            'at' => now()->subMinutes(25 - $i * 5)->toIso8601String(),
        ]));
    }

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertNotSentTo($user, TransitDisruptionNotification::class);
});
