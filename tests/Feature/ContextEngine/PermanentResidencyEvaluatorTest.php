<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\Evaluators\PermanentResidencyEvaluator;
use App\ContextEngine\ScoredAction;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

uses()->group('context-engine');

beforeEach(function () {
    // Push gating is quiet-hours aware (22:00–06:00) and rate-limited per
    // user-id in shared Redis. Travel to a unique future daytime so the run is
    // deterministic and throttle keys never collide with recycled user ids.
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));

    // The producer's announce-once gate is cache-backed; user ids recycle
    // across the suite, so clear it to start every test un-announced.
    Cache::flush();

    // Parallel test processes share Redis; flush this file's ZSET namespace.
    // Lua KEYS() sees database-side keys, so prepend the configured prefix.
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'pending_actions:*'
    );

    // Inserting fires ScoredActionPushDispatcher too (push_via_bus defaults on);
    // fake notifications so its send stays inert and no NotificationSent fires.
    Notification::fake();
});

/**
 * A non-EU skilled worker four years in — comfortably past the 36-month
 * Niederlassungserlaubnis threshold.
 */
function eligibleResident(): User
{
    return User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYears(4)->toDateString()],
    ]);
}

test('an eligible resident gets a success action on the alert_page channel', function () {
    $user = eligibleResident();

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    $action = collect(app(ActionBus::class)->topK($user->id, 10))
        ->firstWhere('type', 'permanent_residency_eligible');

    expect($action)->not->toBeNull()
        ->and($action->severity)->toBe('success')
        ->and($action->deliverChannels)->toContain(ScoredAction::CHANNEL_ALERT_PAGE);
});

test('the eligibility milestone lands in the Good news lane', function () {
    $user = eligibleResident();

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    $alert = Alert::where('user_id', $user->id)->firstOrFail();
    expect($alert->lane)->toBe('good')
        ->and($alert->category)->toBe('bureau')
        ->and($alert->severity)->toBe('success')
        ->and($alert->title)->toContain('permanent residency');
});

test('a resident below the track threshold gets nothing', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYear()->toDateString()],
    ]);

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty()
        ->and(Alert::where('user_id', $user->id)->count())->toBe(0);
});

test('an EU citizen never gets the hint even past five years', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYears(6)->toDateString()],
    ]);

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(Alert::where('user_id', $user->id)->count())->toBe(0);
});

test('eligibility is announced only once per threshold', function () {
    $user = eligibleResident();

    app(PermanentResidencyEvaluator::class)->evaluate($user);
    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(Alert::where('user_id', $user->id)->count())->toBe(1);
});
