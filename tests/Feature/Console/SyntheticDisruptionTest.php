<?php

use App\Console\Commands\Controls\SyntheticDisruption;
use App\ContextEngine\ActionBus;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\Alert;
use App\Models\User;
use App\Models\UserPlace;
use Illuminate\Support\Collection;

uses()->group('context-engine');

beforeEach(function () {
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));
});

/** Synthetic disruption actions in a user's bus (shared Redis, so filter by key). */
function syntheticActions(int $userId): Collection
{
    return collect(app(ActionBus::class)->topK($userId, 50))
        ->filter(fn ($action) => str_starts_with($action->actionKey, 'disruption:999999999:'));
}

test('the canary reaches only its own user and leaves no alert behind', function () {
    $riders = User::factory()->onboarded()->count(2)->create();
    $riders->each(fn (User $rider) => UserPlace::factory()->create(['user_id' => $rider->id]));

    $this->artisan('controls:synthetic-disruption')->assertSuccessful();

    $canary = User::where('email', SyntheticDisruption::CANARY_EMAIL)->firstOrFail();

    // Never onboarded, so no real evaluator or onboarding stat counts it.
    expect($canary->onboarded_at)->toBeNull()
        ->and(Alert::count())->toBe(0);

    foreach ($riders as $rider) {
        expect(syntheticActions($rider->id))->toBeEmpty();
    }
});

test('repeat runs reuse the one canary user', function () {
    $this->artisan('controls:synthetic-disruption')->assertSuccessful();
    $this->artisan('controls:synthetic-disruption')->assertSuccessful();

    expect(User::where('email', SyntheticDisruption::CANARY_EMAIL)->count())->toBe(1);
});

test('a synthetic event reaches its target alone, tagged, with no alert', function () {
    $rider = User::factory()->onboarded()->create();
    $canary = User::factory()->create();

    event(new TransitDisruptionDetected(
        disruptionId: 999_999_999,
        lines: ['12'],
        stopsAffected: [],
        severity: 'critical',
        bbox: null,
        expiresAt: now()->addMinutes(15),
        syntheticUserId: $canary->id,
    ));

    $action = syntheticActions($canary->id)->first();

    expect(syntheticActions($rider->id))->toBeEmpty()
        ->and($action)->not->toBeNull()
        ->and($action->payload['synthetic'] ?? null)->toBeTrue()
        ->and(Alert::count())->toBe(0);

    app(ActionBus::class)->clear($canary->id);
});
