<?php

use App\Bureaucracy\PermanentResidencyEligibility;
use App\ContextEngine\ActionBus;
use App\ContextEngine\Evaluators\PermanentResidencyEvaluator;
use App\Models\Alert;
use App\Models\User;
use App\Profile\ProfileEngine;
use Illuminate\Notifications\Events\NotificationSent;
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

test('legacy elapsed-time estimates cannot publish an eligibility action', function () {
    $user = eligibleResident();

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty();
    Notification::assertNothingSent();
});

test('legacy residency dates do not produce an unreviewed Good news alert', function () {
    $user = eligibleResident();

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(Alert::where('user_id', $user->id)->count())->toBe(0);
    expect(app(PermanentResidencyEligibility::class)->for(app(ProfileEngine::class)->build($user)))->toBeNull();
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

test('daily repeats cannot re-enable legacy eligibility publication', function () {
    $user = eligibleResident();

    app(PermanentResidencyEvaluator::class)->evaluate($user);
    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(Alert::where('user_id', $user->id)->count())->toBe(0);
});
