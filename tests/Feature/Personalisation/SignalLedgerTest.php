<?php

use App\Composer\ActivePlanStore;
use App\Composer\AppointmentRepository;
use App\Composer\Constraints;
use App\Composer\TasteSignals;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserSignal;
use App\Personalisation\Affinities;
use App\Personalisation\AffinityAggregator;
use App\Personalisation\Pseudonym;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\SignalSurface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function optedIn(bool $on = true): User
{
    $user = User::factory()->onboarded()->create();
    $user->userSetting()->create(['settings' => ['personalisation' => $on]]);

    return $user->fresh();
}

function ledger(User $user): Collection
{
    return DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->get();
}

/** Back-date a user's ledger rows so decay and windows can be checked. */
function ageSignals(User $user, int $days): void
{
    // Days of 24 hours, in UTC: a Berlin calendar day across a clock change is 23 or 25 (25 Oct 2026).
    DB::table('user_signals')->where('subject_key', Pseudonym::for($user))
        ->update(['created_at' => now()->utc()->subDays($days)->toIso8601String()]);
}

test('personalisation is off by default and nothing is recorded without the opt-in', function () {
    $user = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld']);

    expect($user->personalises())->toBeFalse();

    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'saved'])->assertOk();
    $this->postJson("/api/places/{$spot->id}/feedback", ['action' => 'not_interested'])->assertOk();
    app(SignalRecorder::class)->record($user, SignalAction::TripTaken, SignalSurface::Travel, SignalItemType::Spot, $spot->id);

    expect(DB::table('user_signals')->count())->toBe(0)
        ->and(DB::table('user_place_negatives')->count())->toBe(0)
        ->and(app(Affinities::class)->for($user)->isEmpty())->toBeTrue();
});

test('the opt-in is set through user settings and turning it off erases the ledger', function () {
    $user = User::factory()->onboarded()->create();
    $spot = Spot::factory()->create();

    $this->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])
        ->assertOk()->assertJsonPath('settings.personalisation', true);
    // Each real request loads the account afresh; the test reuses one instance.
    $this->actingAs($user->fresh())->postJson("/api/places/{$spot->id}/feedback", ['action' => 'not_interested'])->assertOk();
    expect(ledger($user))->toHaveCount(1);

    $this->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();

    expect(ledger($user))->toHaveCount(0)
        ->and(DB::table('user_place_negatives')->count())->toBe(0);
});

test('a recorded signal carries the item, its features and a coarse context, never a location', function () {
    $user = optedIn();
    $spot = Spot::factory()->create(['category' => 'bar', 'veedel' => 'Ehrenfeld', 'price_range' => '€€', 'lat' => 50.94, 'lng' => 6.92]);

    $this->travelTo(now('Europe/Berlin')->next('Saturday')->setTime(20, 0));
    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'saved'])->assertOk();

    $row = ledger($user)->sole();
    expect($row->action)->toBe('saved')
        ->and($row->item_type)->toBe('spot')
        ->and((int) $row->item_id)->toBe($spot->id)
        ->and($row->category)->toBe('bar')
        ->and($row->veedel)->toBe('Ehrenfeld')
        ->and($row->price_band)->toBe('mid')
        ->and(json_decode($row->context, true))->toMatchArray(['daypart' => 'evening', 'week' => 'weekend'])
        ->and($row->context)->not->toContain('50.94');
});

test('the pseudonym is a keyed hash, not the raw account id', function () {
    $user = optedIn();
    $spot = Spot::factory()->create();
    app(SignalRecorder::class)->record($user, SignalAction::Saved, SignalSurface::Places, SignalItemType::Spot, $spot->id);

    $subject = ledger($user)->sole()->subject_key;
    expect($subject)->toHaveLength(64)
        ->not->toBe((string) $user->id)
        ->not->toBe(hash('sha256', (string) $user->id))
        ->toBe(Pseudonym::for($user->id))
        ->and(Pseudonym::for($user->id + 1))->not->toBe($subject);
});

test('a signal one half-life old counts half', function () {
    $user = optedIn();
    $spot = Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Nippes']);
    app(SignalRecorder::class)->record($user, SignalAction::TripTaken, SignalSurface::Travel, SignalItemType::Spot, $spot->id);

    app(AffinityAggregator::class)->rebuild();
    expect(app(Affinities::class)->for($user)->score('category', 'cafe'))->toEqualWithDelta(5.0, 0.001);

    ageSignals($user, 60); // the taste half-life
    app(AffinityAggregator::class)->rebuild();
    $snapshot = app(Affinities::class)->for($user);

    expect($snapshot->score('category', 'cafe'))->toEqualWithDelta(2.5, 0.001)
        ->and($snapshot->score('veedel', 'Nippes'))->toEqualWithDelta(2.5, 0.001)
        ->and($snapshot->affinities['category'])->toHaveKey('all');
});

test('a swapped-out stop is a negative for that place in its context, on the place half-life', function () {
    $user = optedIn();
    $spot = Spot::factory()->create(['category' => 'museum']);
    $saturday = now('Europe/Berlin')->next('Saturday')->setTime(15, 0);
    app(SignalRecorder::class)->planSlots($user, SignalAction::StopSwappedOut, [['id' => "spot:{$spot->id}", 'start_at' => $saturday->toIso8601String()]]);

    ageSignals($user, 90);
    app(AffinityAggregator::class)->rebuild();
    $snapshot = app(Affinities::class)->for($user);

    expect($snapshot->placeScore('spot', $spot->id, 'weekend'))->toEqualWithDelta(-1.0, 0.001)
        ->and($snapshot->placeScore('spot', $spot->id, 'weekday'))->toBe(0.0)
        ->and($snapshot->score('category', 'museum'))->toBe(0.0)
        ->and($snapshot->isHidden('spot', $spot->id))->toBeFalse();
});

test('"not for me" hides a place for 90 days and clearing it lifts the hide', function () {
    $user = optedIn();
    $spot = Spot::factory()->create();

    $this->actingAs($user)->postJson("/api/places/{$spot->id}/feedback", ['action' => 'not_interested'])->assertOk();
    expect(app(Affinities::class)->for($user)->isHidden('spot', $spot->id))->toBeTrue(); // live, before the nightly run

    $this->travelTo(now()->addDays(89));
    app(AffinityAggregator::class)->rebuild();
    expect(app(Affinities::class)->for($user)->isHidden('spot', $spot->id))->toBeTrue();

    $this->travelTo(now()->addDays(2));
    app(AffinityAggregator::class)->rebuild();
    expect(app(Affinities::class)->for($user)->isHidden('spot', $spot->id))->toBeFalse();

    $this->travelBack();
    $other = Spot::factory()->create();
    $this->postJson("/api/places/{$other->id}/feedback", ['action' => 'not_interested'])->assertOk();
    $this->postJson("/api/places/{$other->id}/feedback", ['action' => 'clear'])->assertOk();
    expect(app(Affinities::class)->for($user)->isHidden('spot', $other->id))->toBeFalse()
        ->and(ledger($user)->where('item_id', $other->id))->toHaveCount(0);
});

test('"not now" hides a place for 7 days', function () {
    $user = optedIn();
    $spot = Spot::factory()->create();
    app(SignalRecorder::class)->record($user, SignalAction::NotNow, SignalSurface::Today, SignalItemType::Spot, $spot->id);

    expect(app(Affinities::class)->for($user)->isHidden('spot', $spot->id))->toBeTrue();

    $this->travelTo(now()->addDays(6));
    app(AffinityAggregator::class)->rebuild();
    expect(app(Affinities::class)->for($user)->isHidden('spot', $spot->id))->toBeTrue();

    $this->travelTo(now()->addDays(2));
    app(AffinityAggregator::class)->rebuild();
    $snapshot = app(Affinities::class)->for($user);
    expect($snapshot->isHidden('spot', $spot->id))->toBeFalse()
        ->and($snapshot->placeScore('spot', $spot->id))->toBeLessThan(0.0);
});

test('three untouched showings give −0.3, and a touch cancels it', function () {
    $user = optedIn();
    $ignored = Spot::factory()->create(['category' => 'cafe']);
    $touched = Spot::factory()->create(['category' => 'bar']);
    $recorder = app(SignalRecorder::class);

    foreach ([3, 2, 1] as $hoursAgo) {
        $this->travelTo(now()->subHours($hoursAgo));
        $recorder->fromClient($user, [
            ['action' => 'impression', 'surface' => 'places', 'item_type' => 'spot', 'item_id' => $ignored->id, 'position' => 0],
            ['action' => 'impression', 'surface' => 'places', 'item_type' => 'spot', 'item_id' => $touched->id, 'position' => 1],
        ]);
        $this->travelBack();
    }
    $recorder->record($user, SignalAction::DetailViewed, SignalSurface::PlaceSheet, SignalItemType::Spot, $touched->id);

    app(AffinityAggregator::class)->rebuild();
    $snapshot = app(Affinities::class)->for($user);

    expect($snapshot->score('category', 'cafe'))->toEqualWithDelta(-0.3, 0.001)
        ->and($snapshot->placeScore('spot', $ignored->id))->toEqualWithDelta(-0.3, 0.001)
        ->and($snapshot->score('category', 'bar'))->toEqualWithDelta(1.0, 0.001);
});

test('two showings in one hour are one showing', function () {
    $user = optedIn();
    $spot = Spot::factory()->create(['category' => 'cafe']);
    $impression = ['action' => 'impression', 'surface' => 'today', 'item_type' => 'spot', 'item_id' => $spot->id, 'position' => 2];

    $this->travelTo(now()->startOfHour()->addMinutes(5));
    app(SignalRecorder::class)->fromClient($user, [$impression]);
    app(SignalRecorder::class)->fromClient($user, [$impression]);
    $this->travelTo(now()->addHour());
    app(SignalRecorder::class)->fromClient($user, [$impression]);

    app(AffinityAggregator::class)->rebuild();
    expect(app(Affinities::class)->for($user)->score('category', 'cafe'))->toBe(0.0);
});

test('aggregation is idempotent', function () {
    // One instant for both runs: decay is computed from the time given, so two
    // separate now() calls differed in the last digits and failed CI at random.
    $this->freezeTime();
    $user = optedIn();
    $spot = Spot::factory()->create(['category' => 'park']);
    app(SignalRecorder::class)->record($user, SignalAction::Saved, SignalSurface::Places, SignalItemType::Spot, $spot->id);
    $at = now();
    $affinities = fn () => DB::table('user_affinities')->orderBy('dimension')->orderBy('key')->orderBy('context')
        ->get(['dimension', 'key', 'context', 'score'])
        ->map(fn ($row) => [$row->dimension, $row->key, $row->context, round((float) $row->score, 6)])->all();

    $first = app(AffinityAggregator::class)->rebuild($at);
    $rows = $affinities();
    $second = app(AffinityAggregator::class)->rebuild($at);

    expect($second)->toBe($first)
        ->and($rows)->not->toBe([])
        ->and($affinities())->toBe($rows);
});

test('saving an event and starting a trip to a planned place are recorded', function () {
    $user = optedIn();
    $event = Event::factory()->create(['category' => 'music', 'is_free' => true]);

    app(SignalRecorder::class)->record($user, SignalAction::Saved, SignalSurface::EventSheet, SignalItemType::Event, $event->id, now()->addDays(2));
    $spot = Spot::factory()->create();
    app(SignalRecorder::class)->tripStarted($user, ['name' => 'Somewhere', 'lat' => 0, 'lng' => 0], ['id' => "spot:{$spot->id}"]);

    $rows = ledger($user)->keyBy('action');
    expect($rows['saved']->item_type)->toBe('event')
        ->and($rows['saved']->category)->toBe('music')
        ->and($rows['saved']->price_band)->toBe('free')
        ->and((int) $rows['trip_taken']->item_id)->toBe($spot->id);
});

test('"get there" counts only a tagged request, once a day', function () {
    $user = optedIn();
    $spot = Spot::factory()->create();
    $recorder = app(SignalRecorder::class);

    $recorder->getThere($user, null);
    $recorder->getThere($user, ['item' => 'Somewhere typed']);
    $recorder->getThere($user, ['item' => "spot:{$spot->id}", 'surface' => 'places']);
    $recorder->getThere($user, ['item' => "spot:{$spot->id}", 'surface' => 'places']);

    expect(ledger($user)->pluck('surface')->all())->toBe(['places']);
});

test('"get there" counts from the journey request body only, never its query string', function () {
    Http::fake();
    $user = optedIn();
    $spot = Spot::factory()->create();
    $tag = ['item' => "spot:{$spot->id}", 'surface' => 'place_sheet'];

    $this->actingAs($user)->getJson('/api/journey?'.http_build_query(['to_lat' => 50.94, 'to_lng' => 6.95, 'get_there' => $tag]));
    expect(ledger($user))->toBeEmpty();

    $this->actingAs($user)->postJson('/api/journey/plan', ['to_lat' => 50.94, 'to_lng' => 6.95, 'get_there' => $tag]);
    expect(ledger($user)->pluck('surface')->all())->toBe(['place_sheet']);
});

test('deleting the account deletes its ledger and aggregates', function () {
    $user = optedIn();
    $keep = optedIn();
    $spot = Spot::factory()->create(['category' => 'cafe']);
    foreach ([$user, $keep] as $person) {
        app(SignalRecorder::class)->record($person, SignalAction::NotForMe, SignalSurface::Places, SignalItemType::Spot, $spot->id);
    }
    app(AffinityAggregator::class)->rebuild();
    $subject = Pseudonym::for($user);

    $this->actingAs($user)->delete('/settings/profile', ['password' => 'password'])->assertRedirect();

    expect(User::find($user->id))->toBeNull();
    foreach (['user_signals', 'user_affinities', 'user_place_negatives'] as $table) {
        expect(DB::table($table)->where('subject_key', $subject)->count())->toBe(0)
            ->and(DB::table($table)->where('subject_key', Pseudonym::for($keep))->count())->toBeGreaterThan(0);
    }
});

test('ledger rows past 90 days are pruned', function () {
    $user = optedIn();
    $old = Spot::factory()->create();
    $recent = Spot::factory()->create();
    app(SignalRecorder::class)->record($user, SignalAction::Saved, SignalSurface::Places, SignalItemType::Spot, $old->id);
    ageSignals($user, 91);
    app(SignalRecorder::class)->record($user, SignalAction::Saved, SignalSurface::Places, SignalItemType::Spot, $recent->id);

    $this->artisan('model:prune', ['--model' => [UserSignal::class]])->assertSuccessful();

    expect(ledger($user)->pluck('item_id')->map(fn ($id) => (int) $id)->all())->toBe([$recent->id]);
});

test('the nightly aggregate command runs', function () {
    $this->artisan('signals:aggregate')->assertSuccessful();
});

test('keeping the composed plan records each stop as kept unedited, only with the opt-in', function (bool $on, int $rows) {
    $user = optedIn($on);
    $spot = Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld']);
    $constraints = ['window_start' => now()->toIso8601String(), 'window_end' => now()->addHours(4)->toIso8601String(), 'companions' => null];
    app(ActivePlanStore::class)->save($user, [
        'slots' => [['id' => "spot:{$spot->id}", 'type' => 'spot', 'category' => 'cafe', 'veedel' => 'Ehrenfeld']],
        'constraints' => $constraints,
        'appointment_revision' => app(AppointmentRepository::class)->revision($user, Constraints::fromArray($constraints)),
    ]);

    $this->actingAs($user)->postJson('/composer/keep')->assertOk()->assertJson(['kept' => true]);

    expect(ledger($user)->where('action', 'plan_kept_unedited'))->toHaveCount($rows);
})->with(['opted in' => [true, 1], 'not opted in' => [false, 0]]);

test('keeping with no composed plan is a 404', function () {
    $this->actingAs(optedIn())->postJson('/composer/keep')->assertNotFound();
});

test('taste signals are not written without the opt-in', function () {
    $user = optedIn(false);
    $event = Event::factory()->create();

    app(TasteSignals::class)->eventSaved($user, $event);

    expect(UserEvent::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
