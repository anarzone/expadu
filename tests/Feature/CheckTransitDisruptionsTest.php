<?php

use App\Models\Routine;
use App\Models\User;
use App\Notifications\TransitDisruptionNotification;
use App\Services\DisruptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Notification::fake();
    Cache::flush();

    // Flush Redis throttle keys to prevent cross-test/real-data interference
    try {
        Redis::connection()->flushdb();
    } catch (Throwable) {
        // Redis unavailable
    }

    $this->travelTo(now()->setTime(10, 0));

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

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Notification::assertSentTo($user, TransitDisruptionNotification::class);
});
