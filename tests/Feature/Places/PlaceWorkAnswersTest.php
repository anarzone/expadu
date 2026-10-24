<?php

use App\Enums\SpotFeedbackState;
use App\Models\PlaceWorkAnswer;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Places\OpenNearby;
use App\Places\PlaceFacts;
use App\Places\PlaceWorkAnswers;
use App\Places\RecordPlaceObservation;
use Carbon\CarbonImmutable;

function workCafe(array $attributes = []): Spot
{
    return Spot::factory()->create(['category' => 'cafe', 'source' => 'osm', 'source_id' => 'node/'.random_int(1_000_000, 9_999_999), 'lat' => 50.94, 'lng' => 6.95] + $attributes);
}

/** A person who's been there, with an account older than a day, answering at $at. */
function workAnswer(Spot $spot, string $laptop, ?CarbonImmutable $at = null, ?User $user = null): User
{
    $user ??= User::factory()->create(['created_at' => now()->subMonths(24)]);
    SpotFeedback::query()->create(['user_id' => $user->id, 'spot_id' => $spot->id, 'been_at' => now()]);
    PlaceWorkAnswer::query()->create(['user_id' => $user->id, 'spot_id' => $spot->id, 'laptop' => $laptop, 'answered_at' => $at ?? CarbonImmutable::now()]);

    return $user;
}

function laptopsFact(Spot $spot): array
{
    return app(PlaceFacts::class)->resolve($spot->fresh())['practical']['laptops'];
}

test('only people who marked the place Been there or Saved can answer', function () {
    $spot = workCafe();
    $user = User::factory()->create();

    $this->actingAs($user)->putJson("/api/places/{$spot->id}/work-answer", ['laptop' => 'yes'])->assertForbidden();
    expect(PlaceWorkAnswer::query()->count())->toBe(0);

    SpotFeedback::query()->create(['user_id' => $user->id, 'spot_id' => $spot->id, 'state' => SpotFeedbackState::Saved]);
    $this->actingAs($user)->putJson("/api/places/{$spot->id}/work-answer", ['laptop' => 'yes', 'wifi' => 'yes'])
        ->assertOk()->assertJsonPath('answer.laptop', 'yes')->assertJsonPath('can_answer', true);

    // "More like this" is interest, not a visit: still no.
    $other = User::factory()->create();
    SpotFeedback::query()->create(['user_id' => $other->id, 'spot_id' => $spot->id, 'state' => SpotFeedbackState::MoreLikeThis]);
    $this->actingAs($other)->putJson("/api/places/{$spot->id}/work-answer", ['laptop' => 'no'])->assertForbidden();
});

test('a person can change and withdraw their answer', function () {
    $spot = workCafe();
    $user = User::factory()->create();
    SpotFeedback::query()->create(['user_id' => $user->id, 'spot_id' => $spot->id, 'been_at' => now()]);

    $this->actingAs($user)->putJson("/api/places/{$spot->id}/work-answer", ['laptop' => 'yes'])->assertOk();
    $this->actingAs($user)->putJson("/api/places/{$spot->id}/work-answer", ['laptop' => 'no'])->assertOk()->assertJsonPath('answer.laptop', 'no');
    expect(PlaceWorkAnswer::query()->count())->toBe(1);

    $this->actingAs($user)->deleteJson("/api/places/{$spot->id}/work-answer")->assertOk()->assertJsonPath('answer', null);
    expect(PlaceWorkAnswer::query()->count())->toBe(0);
});

test('answer, chips, withdraw and undo all pass; the place cap is 10 changes a day', function () {
    $spot = workCafe();
    $user = User::factory()->create();
    SpotFeedback::query()->create(['user_id' => $user->id, 'spot_id' => $spot->id, 'been_at' => now()]);
    $url = "/api/places/{$spot->id}/work-answer";

    $this->actingAs($user)->putJson($url, ['laptop' => 'yes'])->assertOk();
    $this->actingAs($user)->putJson($url, ['laptop' => 'yes', 'wifi' => 'yes', 'sockets' => 'no'])->assertOk(); // chips: part of the answer
    $this->actingAs($user)->deleteJson($url)->assertOk();
    $this->actingAs($user)->putJson($url, ['laptop' => 'yes', 'wifi' => 'yes', 'sockets' => 'no'])->assertOk() // Undo: free
        ->assertJsonPath('answer.sockets', 'no');

    // Two changes counted so far; eight more fit, the 11th doesn't. (None of
    // these puts back the answer before the last change, so none is an Undo.)
    $change = fn (int $i): array => ['laptop' => $i % 2 ? 'no' : 'yes', 'wifi' => $i % 4 < 2 ? 'yes' : 'no'];
    foreach (range(1, 8) as $i) {
        $this->actingAs($user)->putJson($url, $change($i))->assertOk();
    }
    $this->actingAs($user)->putJson($url, $change(9))->assertStatus(429);
});

test('20 new answers a day per person', function () {
    $user = User::factory()->create();
    foreach (range(1, 21) as $i) {
        $spot = workCafe(['lng' => 6.95 + $i / 10000]);
        SpotFeedback::query()->create(['user_id' => $user->id, 'spot_id' => $spot->id, 'been_at' => now()]);
        $this->actingAs($user)->putJson("/api/places/{$spot->id}/work-answer", ['laptop' => 'yes'])->assertStatus($i <= 20 ? 200 : 429);
    }
});

test('nothing shows below three people, and three real answers show', function () {
    $spot = workCafe();
    workAnswer($spot, 'yes');
    workAnswer($spot, 'yes');
    app(PlaceWorkAnswers::class)->refresh($spot->id);

    expect(app(PlaceWorkAnswers::class)->totals($spot->id)['laptop'])->toBeNull()
        ->and(laptopsFact($spot)['status'])->toBe('unknown');

    workAnswer($spot, 'yes');
    app(PlaceWorkAnswers::class)->refresh($spot->id);

    expect(app(PlaceWorkAnswers::class)->totals($spot->id)['laptop'])->toMatchArray(['value' => 'yes', 'yes' => 3, 'total' => 3])
        ->and(laptopsFact($spot))->toMatchArray(['value' => 'yes', 'status' => 'known', 'provider' => 'people']);
});

test('the threshold counts real answers within 12 months, unweighted', function () {
    $spot = workCafe();
    // Three answers 7 months old weigh 0.5 each, but they are three people: it shows.
    foreach (range(1, 3) as $_) {
        workAnswer($spot, 'yes', CarbonImmutable::now()->subMonths(7));
    }
    expect(app(PlaceWorkAnswers::class)->totals($spot->id)['laptop']['value'])->toBe('yes');

    // Answers older than 12 months don't count at all.
    $old = workCafe();
    foreach (range(1, 3) as $_) {
        workAnswer($old, 'yes', CarbonImmutable::now()->subMonths(13));
    }
    expect(app(PlaceWorkAnswers::class)->totals($old->id)['laptop'])->toBeNull();
});

test('accounts younger than a day are stored but not counted', function () {
    $spot = workCafe();
    workAnswer($spot, 'yes');
    workAnswer($spot, 'yes');
    workAnswer($spot, 'yes', user: User::factory()->create(['created_at' => now()->subHours(2)]));

    expect(PlaceWorkAnswer::query()->count())->toBe(3)
        ->and(app(PlaceWorkAnswers::class)->totals($spot->id)['laptop'])->toBeNull();
});

test('the age weighting decides the yes share: good, mixed, not good', function () {
    $spot = workCafe();
    workAnswer($spot, 'yes');
    workAnswer($spot, 'no');
    workAnswer($spot, 'no');
    expect(app(PlaceWorkAnswers::class)->totals($spot->id)['laptop']['value'])->toBe('mixed'); // 1/3

    $recent = workCafe();
    workAnswer($recent, 'yes');
    workAnswer($recent, 'yes');
    workAnswer($recent, 'no', CarbonImmutable::now()->subMonths(8));
    // 2 / (2 + 0.5) = 80% yes.
    expect(app(PlaceWorkAnswers::class)->totals($recent->id)['laptop']['value'])->toBe('yes');
});

test("a café's own website rule and OSM outrank people", function () {
    $spot = workCafe();
    foreach (range(1, 3) as $_) {
        workAnswer($spot, 'yes');
    }
    app(PlaceWorkAnswers::class)->refresh($spot->id);
    app(RecordPlaceObservation::class)->record($spot, [
        'provider' => 'website', 'provider_record_id' => 'cafe.test', 'source_url' => 'https://cafe.test/',
        'observed_at' => now()->subDay()->toIso8601String(), 'ingestion_key' => 'website:test:'.$spot->id,
        'payload' => ['practical' => ['laptops' => 'limited', 'laptops:conditional' => 'no @ (Sa-Su)'], 'evidence' => ['laptops' => 'Laptops sind am Wochenende nicht erlaubt.']],
    ]);

    expect(laptopsFact($spot))->toMatchArray(['value' => 'limited', 'status' => 'known', 'provider' => 'website', 'disputed_by' => ['people'], 'quote' => 'Laptops sind am Wochenende nicht erlaubt.', 'source_url' => 'https://cafe.test/']);

    // Around here's row carries both: people's counts and the café's rule with its days.
    $row = app(OpenNearby::class)->listed(50.94, 6.95, CarbonImmutable::now(), ['cafe'])->firstWhere('id', $spot->id);
    expect($row['laptop'])->toBe([
        'people' => ['value' => 'yes', 'yes' => 3, 'total' => 3],
        'rule' => ['value' => 'no', 'days' => 'Sa-Su'],
    ]);
});

test("people's fact quotes its count summary, and rows without answers carry no tag", function () {
    $spot = workCafe();
    $quiet = workCafe(['lng' => 6.951]);
    foreach (range(1, 3) as $_) {
        workAnswer($spot, 'yes');
    }
    app(PlaceWorkAnswers::class)->refresh($spot->id);

    expect(laptopsFact($spot)['quote'])->toBe("3 of 3 people who've been there said yes (last 12 months)");
    $rows = app(OpenNearby::class)->listed(50.94, 6.95, CarbonImmutable::now(), ['cafe'])->keyBy('id');
    expect($rows[$quiet->id]['laptop'])->toBeNull()
        ->and($rows[$spot->id]['laptop'])->toBe(['people' => ['value' => 'yes', 'yes' => 3, 'total' => 3], 'rule' => null]);
});

test('deleting the account erases their answers and recomputes the place', function () {
    $spot = workCafe();
    workAnswer($spot, 'yes');
    workAnswer($spot, 'yes');
    $leaving = workAnswer($spot, 'yes');
    app(PlaceWorkAnswers::class)->refresh($spot->id);
    expect(laptopsFact($spot)['value'])->toBe('yes');

    $leaving->delete();

    expect(PlaceWorkAnswer::query()->where('user_id', $leaving->id)->exists())->toBeFalse()
        ->and(PlaceWorkAnswer::query()->count())->toBe(2)
        ->and(laptopsFact($spot)['status'])->toBe('unknown');
});

test('their data download lists only their own answers', function () {
    $spot = workCafe(['name' => 'Café Eins']);
    $other = workCafe(['name' => 'Café Zwei']);
    $me = workAnswer($spot, 'yes');
    workAnswer($other, 'no');

    $this->actingAs($me)->getJson('/api/places/work-answers')
        ->assertOk()
        ->assertJsonCount(1, 'answers')
        ->assertJsonPath('answers.0.place.name', 'Café Eins')
        ->assertJsonPath('answers.0.laptop', 'yes');
});

test('the place totals never expose who answered', function () {
    $spot = workCafe();
    $me = workAnswer($spot, 'yes');
    workAnswer($spot, 'yes');
    workAnswer($spot, 'no');

    $json = $this->actingAs($me)->getJson("/api/places/{$spot->id}/work-answer")->assertOk()->json();

    expect(array_keys($json))->toBe(['can_answer', 'answer', 'totals'])
        ->and($json['totals']['laptop'])->toBe(['value' => 'mixed', 'yes' => 2, 'total' => 3]);
});

test('answers older than 18 months are pruned', function () {
    $spot = workCafe();
    workAnswer($spot, 'yes', CarbonImmutable::now()->subMonths(19));
    workAnswer($spot, 'yes', CarbonImmutable::now()->subMonths(2));

    $this->artisan('places:prune-work-answers')->assertSuccessful();

    expect(PlaceWorkAnswer::query()->count())->toBe(1);
});
