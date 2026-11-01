<?php

use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Personalisation\Pseudonym;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function signalUser(bool $optedIn): User
{
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['personalisation' => $optedIn]]);

    return $user->fresh();
}

test('an opted-in batch of impressions and detail views is recorded', function () {
    $user = signalUser(true);
    $spot = Spot::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->postJson('/api/signals', ['signals' => [
        ['action' => 'impression', 'surface' => 'places', 'item_type' => 'spot', 'item_id' => $spot->id, 'position' => 3],
        ['action' => 'detail_viewed', 'surface' => 'event_sheet', 'item_type' => 'event', 'item_id' => $event->id, 'dwell_seconds' => 14],
    ]])->assertStatus(202);

    $rows = DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->get()->keyBy('action');
    expect($rows)->toHaveCount(2)
        ->and((int) $rows['impression']->position)->toBe(3)
        ->and($rows['impression']->surface)->toBe('places')
        ->and($rows['detail_viewed']->item_type)->toBe('event');
});

test('without the opt-in a valid batch is accepted and dropped', function () {
    $spot = Spot::factory()->create();

    $this->actingAs(signalUser(false))->postJson('/api/signals', ['signals' => [
        ['action' => 'impression', 'surface' => 'today', 'item_type' => 'spot', 'item_id' => $spot->id, 'position' => 0],
    ]])->assertStatus(202);

    expect(DB::table('user_signals')->count())->toBe(0);
});

test('the endpoint rejects what is not allow-listed', function (array $signal) {
    $spot = Spot::factory()->create();
    $base = ['action' => 'impression', 'surface' => 'places', 'item_type' => 'spot', 'item_id' => $spot->id, 'position' => 0];

    $this->actingAs(signalUser(true))->postJson('/api/signals', ['signals' => [array_merge($base, $signal)]])
        ->assertUnprocessable();

    expect(DB::table('user_signals')->count())->toBe(0);
})->with([
    'unknown action' => [['action' => 'trip_taken']],
    'server-side action' => [['action' => 'saved']],
    'unknown surface' => [['surface' => 'homepage']],
    'unknown item type' => [['item_type' => 'user']],
    'free text' => [['note' => 'my friend’s flat']],
    'coordinates' => [['lat' => 50.94]],
    'short detail view' => [['action' => 'detail_viewed', 'position' => null, 'dwell_seconds' => 4]],
    'impression without position' => [['position' => null]],
]);

test('the endpoint rejects items that do not exist', function () {
    $this->actingAs(signalUser(true))->postJson('/api/signals', ['signals' => [
        ['action' => 'impression', 'surface' => 'places', 'item_type' => 'spot', 'item_id' => 999999999, 'position' => 0],
    ]])->assertUnprocessable()->assertJsonValidationErrors('signals.0.item_id');
});

test('the endpoint rejects an oversized batch', function () {
    $spot = Spot::factory()->create();
    $signals = array_fill(0, 51, ['action' => 'impression', 'surface' => 'places', 'item_type' => 'spot', 'item_id' => $spot->id, 'position' => 0]);

    $this->actingAs(signalUser(true))->postJson('/api/signals', ['signals' => $signals])
        ->assertUnprocessable()->assertJsonValidationErrors('signals');
});

test('the endpoint needs a signed-in person and carries its own throttle', function () {
    $this->postJson('/api/signals', ['signals' => []])->assertUnauthorized();

    expect(Route::getRoutes()->getByName('api.signals')->gatherMiddleware())->toContain('throttle:signals');
});

test('"Not now" from the client hides the place for a week, and a share counts', function () {
    $user = signalUser(true);
    $spot = Spot::factory()->create();
    $event = Event::factory()->create();

    $this->actingAs($user)->postJson('/api/signals', ['signals' => [
        ['action' => 'not_now', 'surface' => 'today', 'item_type' => 'spot', 'item_id' => $spot->id],
        ['action' => 'shared', 'surface' => 'event_sheet', 'item_type' => 'event', 'item_id' => $event->id],
    ]])->assertStatus(202);

    $hide = DB::table('user_place_negatives')->where('subject_key', Pseudonym::for($user))->where('item_id', $spot->id)->first();
    expect(DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->pluck('action')->sort()->values()->all())->toBe(['not_now', 'shared'])
        ->and($hide)->not->toBeNull()
        ->and(now()->addDays(6)->lessThan($hide->hidden_until))->toBeTrue()
        ->and(now()->addDays(8)->greaterThan($hide->hidden_until))->toBeTrue();
});
