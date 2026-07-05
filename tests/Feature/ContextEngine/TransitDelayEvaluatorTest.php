<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Events\Context\TransitDelayDetected;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\UserTransitLinesService;
use Illuminate\Support\Facades\Redis;

uses()->group('context-engine');

beforeEach(function () {
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));

    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'pending_actions:*'
    );
});

function delayFakeUserLines(array $lines): void
{
    test()->mock(UserTransitLinesService::class, function ($mock) use ($lines) {
        $mock->shouldReceive('getRelevantLines')->andReturn([
            'lines' => collect($lines),
            'stops' => collect(),
            'context' => [],
        ]);
    });
}

test('a moderate delay on a matched line is alert-page only, no dashboard tile', function () {
    // "A saved place is on this line" is not "boarding now", so a delay is not
    // act-now enough for Today — it stays on the Alerts record.
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    delayFakeUserLines(['1']);

    event(new TransitDelayDetected(line: '1', direction: 'Bensberg', delayMin: 18));

    $action = collect(app(ActionBus::class)->topK($user->id, 10))
        ->firstWhere('type', 'transit_delay');

    expect($action)->not->toBeNull()
        ->and($action->deliverChannels)->toBe([ScoredAction::CHANNEL_ALERT_PAGE]);
});

test('a major delay still pushes but never lands a dashboard tile', function () {
    // Severe delays (>= 30 min) keep the push channel — but not the dashboard,
    // which the fusion step (a live leave-by) is what earns back. transit_delay
    // push is commute-window only (6–9am / 4–7pm), so assert inside one.
    $this->travelTo(now()->setTime(8, 0));

    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    delayFakeUserLines(['1']);

    // User ids recycle while Redis throttle keys persist across runs; clear them
    // or ActionBus strips the push channel and the assertion flakes.
    Redis::del(
        "notif_throttle:last:{$user->id}",
        'notif_throttle:hour:'.$user->id.':'.now()->format('Y-m-d-H'),
        'notif_throttle:day:'.$user->id.':'.now()->format('Y-m-d'),
    );

    event(new TransitDelayDetected(line: '1', direction: 'Bensberg', delayMin: 35));

    $action = collect(app(ActionBus::class)->topK($user->id, 10))
        ->firstWhere('type', 'transit_delay');

    expect($action)->not->toBeNull()
        ->and($action->deliverChannels)->toContain(ScoredAction::CHANNEL_PUSH)
        ->and($action->deliverChannels)->not->toContain(ScoredAction::CHANNEL_DASHBOARD);
});
