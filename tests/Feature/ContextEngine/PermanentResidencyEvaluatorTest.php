<?php

use App\Bureaucracy\PermanentResidencyEligibility;
use App\ContextEngine\ActionBus;
use App\ContextEngine\Evaluators\PermanentResidencyEvaluator;
use App\Models\Alert;
use App\Models\User;
use App\Profile\ProfileEngine;
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
    config(['context_engine.push_via_bus' => true]);
});

// Permit age is legacy profile data, not a reviewed eligibility assessment.
function residentWithLongHeldPermit(int $years = 4): User
{
    return User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYears($years)->toDateString()],
    ]);
}

test('long permit age alone produces neither an eligibility claim nor an action', function (int $years) {
    $user = residentWithLongHeldPermit($years);
    $profile = app(ProfileEngine::class)->build($user);

    expect(app(PermanentResidencyEligibility::class)->for($profile))->toBeNull();

    app(PermanentResidencyEvaluator::class)->evaluate($user, $profile);

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty()
        ->and(Alert::where('user_id', $user->id)->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['four years' => 4, 'ten years' => 10]);

test('long permit age does not create a good-news alert or notification', function () {
    $user = residentWithLongHeldPermit();

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty()
        ->and(Alert::where('user_id', $user->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a recently issued permit alone produces no eligibility claim', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYear()->toDateString()],
    ]);

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty()
        ->and(Alert::where('user_id', $user->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

test('an EU citizen never gets the hint even past five years', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYears(6)->toDateString()],
    ]);

    app(PermanentResidencyEvaluator::class)->evaluate($user);

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty()
        ->and(Alert::where('user_id', $user->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

test('retries and expiry of the old announcement window never announce duration-only eligibility', function () {
    $user = residentWithLongHeldPermit();
    $evaluator = app(PermanentResidencyEvaluator::class);

    foreach ([0, 0, 1, 181] as $days) {
        $this->travel($days)->days();
        $evaluator->evaluate($user);

        expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty()
            ->and(Alert::where('user_id', $user->id)->count())->toBe(0);
        Notification::assertNothingSent();
    }
});
