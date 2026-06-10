<?php

use App\Events\Context\TransitDisruptionDetected;
use App\Services\DisruptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
});

function mockDisruptions(array $disruptions): void
{
    test()->mock(DisruptionService::class, function ($mock) use ($disruptions) {
        $mock->shouldReceive('getLineDisruptions')->andReturn($disruptions);
    });
}

test('emits a context event for each new disruption', function () {
    Event::fake([TransitDisruptionDetected::class]);

    mockDisruptions([
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
        [
            'id' => 'news_100',
            'title' => 'Line 7 disruption',
            'description' => 'Signal failure',
            'severity' => 'minor',
            'type' => 'line',
            'affected_lines' => ['7'],
            'started_at' => now()->toIso8601String(),
            'estimated_end' => null,
            'source' => 'kvb_news',
        ],
    ]);

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Event::assertDispatchedTimes(TransitDisruptionDetected::class, 2);
    Event::assertDispatched(TransitDisruptionDetected::class, function ($event) {
        return $event->lines === ['3'] && $event->severity === 'major';
    });
});

test('does not re-emit disruptions already seen', function () {
    Event::fake([TransitDisruptionDetected::class]);

    mockDisruptions([
        [
            'id' => 'news_101',
            'title' => 'Line 3 disruption',
            'description' => 'Construction',
            'severity' => 'major',
            'type' => 'line',
            'affected_lines' => ['3'],
            'started_at' => now()->toIso8601String(),
            'estimated_end' => null,
            'source' => 'kvb_news',
        ],
    ]);

    $this->artisan('transit:check-disruptions')->assertSuccessful();
    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Event::assertDispatchedTimes(TransitDisruptionDetected::class, 1);
});

test('emits nothing when there are no disruptions', function () {
    Event::fake([TransitDisruptionDetected::class]);

    mockDisruptions([]);

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Event::assertNotDispatched(TransitDisruptionDetected::class);
});
