<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Events\Context\RhineLevelChanged;
use App\Models\User;
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

test('a rhine level crossing is alert-page only, never a Today dashboard tile', function () {
    // A river gauge reading is not an act-now Today need for the general
    // population — it belongs on the Alerts record, not the dashboard.
    $user = User::factory()->onboarded()->create();

    event(new RhineLevelChanged(level: 5.2, thresholdCrossed: 'warning'));

    $action = collect(app(ActionBus::class)->topK($user->id, 10))
        ->firstWhere('type', 'rhine_level');

    expect($action)->not->toBeNull()
        ->and($action->deliverChannels)->toContain(ScoredAction::CHANNEL_ALERT_PAGE)
        ->and($action->deliverChannels)->not->toContain(ScoredAction::CHANNEL_DASHBOARD);
});
