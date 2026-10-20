<?php

use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\User;
use App\Notifications\TransitDelayNotification;
use App\Services\DisruptionService;
use App\Support\NotificationThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses()->group('context-engine');

beforeEach(function () {
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));
    config(['context_engine.push_via_bus' => true]);
});

test('a critical disruption may push in quiet hours; a major one may not', function () {
    $user = User::factory()->create();
    $this->travelTo(now()->setTime(23, 0));

    expect(NotificationThrottle::canPush($user, 'transit_disruption', 'critical'))->toBeTrue()
        ->and(NotificationThrottle::canPush($user, 'transit_disruption', 'major'))->toBeFalse();
});

test('weather alerts push only in commute windows and the Rhine never pushes', function () {
    $user = User::factory()->create();

    expect(NotificationThrottle::canPush($user, 'weather_alert'))->toBeFalse()
        ->and(NotificationThrottle::canPush($user, 'rhine_level'))->toBeFalse();

    $this->travelTo(now()->setTime(8, 0));

    expect(NotificationThrottle::canPush($user, 'weather_alert'))->toBeTrue();
});

test('the same situation pushes once; only an escalation pushes again', function () {
    Notification::fake();
    $user = User::factory()->create();

    $delay = fn (string $severity) => new ScoredAction(
        type: 'transit_delay',
        actionKey: "delay:18:north:user:{$user->id}",
        score: 50.0,
        severity: $severity,
        validUntil: CarbonImmutable::now()->addHours(2),
        deliverChannels: [ScoredAction::CHANNEL_PUSH],
        payload: ['line' => '18', 'delay_min' => 30, 'stop_id' => 'Neumarkt'],
        createdAt: CarbonImmutable::now(),
    );
    $dispatch = fn (ScoredAction $action) => app(ScoredActionPushDispatcher::class)
        ->handle(new ScoredActionInserted($user, $action));

    $dispatch($delay('major'));
    $dispatch($delay('major'));

    Notification::assertSentToTimes($user, TransitDelayNotification::class, 1);

    $dispatch($delay('critical'));

    Notification::assertSentToTimes($user, TransitDelayNotification::class, 2);
});

test('a disruption that stays live is not announced again when its seen-list entry would have lapsed', function () {
    Event::fake([TransitDisruptionDetected::class]);
    $this->mock(DisruptionService::class, fn ($mock) => $mock->shouldReceive('getLineDisruptions')->andReturn([[
        'id' => 'news_9001',
        'title' => 'Line 5 disruption',
        'description' => 'Construction',
        'severity' => 'critical',
        'type' => 'line',
        'affected_lines' => ['5'],
        'started_at' => now()->toIso8601String(),
        'estimated_end' => null,
        'source' => 'kvb_news',
    ]]));

    // Polled every 10 minutes in production; 5-hour gaps straddle the 6-hour TTL.
    $this->artisan('transit:check-disruptions')->assertSuccessful();
    $this->travel(5)->hours();
    $this->artisan('transit:check-disruptions')->assertSuccessful();
    $this->travel(5)->hours();
    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Event::assertDispatchedTimes(TransitDisruptionDetected::class, 1);
});
