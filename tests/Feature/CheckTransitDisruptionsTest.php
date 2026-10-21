<?php

use App\ContextEngine\ActionBus;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\Alert;
use App\Models\User;
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

test('two live disruptions stay two: each keeps its own action and alert', function () {
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));
    $user = User::factory()->onboarded()->create();

    $disruption = fn (string $id, string $line) => [
        'id' => $id,
        'title' => "Line {$line} disruption",
        'description' => 'Signal failure',
        'severity' => 'major',
        'type' => 'line',
        'affected_lines' => [$line],
        'started_at' => now()->toIso8601String(),
        'estimated_end' => null,
        'source' => 'kvb_news',
    ];
    mockDisruptions([$disruption('news_8101', '3'), $disruption('news_8102', '7')]);

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    $keys = collect(app(ActionBus::class)->topK($user->id, 50))->pluck('actionKey');

    // Both ids used to collapse to disruption:0, the second overwriting the first.
    expect($keys)->toContain("disruption:news_8101:user:{$user->id}")
        ->and($keys)->toContain("disruption:news_8102:user:{$user->id}")
        ->and(Alert::where('user_id', $user->id)->orderBy('group_key')->pluck('group_key')->all())
        ->toBe(['disruption:news_8101', 'disruption:news_8102']);

    app(ActionBus::class)->clear($user->id);
});

test('an alert lasts until the notice says it ends; a Betriebslage lease is no end', function () {
    Event::fake([TransitDisruptionDetected::class]);
    $end = now()->addDays(3)->startOfMinute();

    mockDisruptions([
        ['id' => 'news_201', 'title' => 'Bis 23.11.2026: Trennung der Linie 18', 'description' => '', 'severity' => 'moderate', 'type' => 'line',
            'affected_lines' => ['18'], 'started_at' => now()->toIso8601String(), 'estimated_end' => $end->toIso8601String(), 'source' => 'kvb'],
        ['id' => 'news_202', 'title' => 'Linie 16: Verspätungen', 'description' => '', 'severity' => 'minor', 'type' => 'line',
            'affected_lines' => ['16'], 'started_at' => now()->toIso8601String(), 'estimated_end' => now()->addHours(2)->toIso8601String(), 'source' => 'kvb-betriebslage'],
        ['id' => 'news_203', 'title' => 'Linie 7: Umleitung', 'description' => '', 'severity' => 'minor', 'type' => 'line',
            'affected_lines' => ['7'], 'started_at' => now()->toIso8601String(), 'estimated_end' => null, 'source' => 'kvb'],
    ]);

    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Event::assertDispatched(TransitDisruptionDetected::class, fn ($e) => $e->disruptionId === 'news_201' && $e->expiresAt?->equalTo($end));
    Event::assertDispatched(TransitDisruptionDetected::class, fn ($e) => $e->disruptionId === 'news_202' && $e->expiresAt === null);
    Event::assertDispatched(TransitDisruptionDetected::class, fn ($e) => $e->disruptionId === 'news_203' && $e->expiresAt === null);
});
