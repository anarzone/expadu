<?php

use App\Models\Event;
use App\Models\User;
use App\Personalisation\Interleaving;
use App\Personalisation\Pseudonym;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Does the order help? With it on, Today's ideas are drafted from the usual order and the
 * person's own; each idea's order is logged with the list, never shown, and a week's opens give
 * each person one vote.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00', 'Europe/Berlin'));
});

function tonightAt(string $title, string $time, string $category): Event
{
    return Event::factory()->create([
        'title' => $title, 'category' => $category,
        'starts_at' => CarbonImmutable::parse("2026-11-17 {$time}", 'Europe/Berlin')->utc(),
        'ends_at' => CarbonImmutable::parse("2026-11-17 {$time}", 'Europe/Berlin')->addHour()->utc(),
    ]);
}

function onPhone(User $user)
{
    return test()->withToken($user->createToken('iPhone')->plainTextToken);
}

function musicFan(): User
{
    $user = User::factory()->onboarded()->create();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();
    onPhone($user->fresh())->putJson('/api/v1/me/picks', ['kinds' => ['music'], 'done' => true])->assertOk();

    return $user->fresh();
}

test('a draft takes turns, the order with fewer picks first, a fixed coin when level, every idea once', function () {
    $usual = ['a', 'b', 'c', 'd', 'e', 'f'];
    $yours = ['e', 'f', 'a', 'b', 'c', 'd'];

    $draft = Interleaving::draft($usual, $yours, 'someone|2026-11-17');

    expect(collect($draft)->pluck('key')->sort()->values()->all())->toBe($usual)
        ->and(Interleaving::draft($usual, $yours, 'someone|2026-11-17'))->toBe($draft);
    // After every pair of turns both orders have put the same number in.
    foreach ([2, 4, 6] as $upTo) {
        $teams = collect($draft)->take($upTo)->countBy('team');
        expect($teams[Interleaving::USUAL] ?? 0)->toBe($teams[Interleaving::YOURS] ?? 0);
    }
    // Each one's first pick is its own best.
    expect(collect($draft)->firstWhere('team', Interleaving::USUAL)['key'])->toBe('a')
        ->and(collect($draft)->firstWhere('team', Interleaving::YOURS)['key'])->toBe('e')
        // The same first few both ways decide nothing.
        ->and(Interleaving::draft($usual, ['a', 'b', 'c', 'd', 'f', 'e'], 'x'))->toBeNull();
});

test('on, Today shows a draft of both orders, logs each idea’s order and never shows it', function () {
    config(['personalisation.interleave' => true]);
    $user = musicFan();
    tonightAt('Play', '18:00', 'stage');
    tonightAt('Cabaret', '19:00', 'stage');
    tonightAt('Walk', '20:00', 'tours');
    tonightAt('Puppets', '21:00', 'kids');
    tonightAt('Gig', '22:00', 'music');

    $ideas = onPhone($user)->getJson('/api/v1/today')->assertOk()->json('ideas');
    $list = DB::table('list_impressions')->where('subject_key', Pseudonym::for($user))->where('surface', 'today_events')->first();
    $shown = json_decode($list->shown, true);

    expect($list->policy)->toBe('interleave')
        ->and(collect($shown)->pluck('team')->unique()->sort()->values()->all())->toBe([Interleaving::USUAL, Interleaving::YOURS])
        ->and(collect($ideas)->pluck('title')->all())->toContain('Gig', 'Play')
        ->and(json_encode(onPhone($user)->getJson('/api/v1/today')->json()))->not->toContain('"team"')
        // The same draft all day.
        ->and(collect(onPhone($user)->getJson('/api/v1/today')->json('ideas'))->pluck('title')->all())->toBe(collect($ideas)->pluck('title')->all());
});

test('🔒 off, or with personalisation off, Today is never drafted', function () {
    tonightAt('Play', '18:00', 'stage');
    tonightAt('Gig', '22:00', 'music');
    $fan = musicFan();
    onPhone($fan)->getJson('/api/v1/today')->assertOk();
    expect(DB::table('list_impressions')->where('subject_key', Pseudonym::for($fan))->value('policy'))->not->toBe('interleave');

    config(['personalisation.interleave' => true]);
    $off = User::factory()->onboarded()->create();
    onPhone($off)->getJson('/api/v1/today')->assertOk();
    expect(DB::table('list_impressions')->where('subject_key', Pseudonym::for($off))->exists())->toBeFalse()
        ->and(Interleaving::on($off))->toBeFalse();
});

test('a week of opens gives each person one vote, and too few votes say so', function () {
    config(['personalisation.interleave' => true]);
    $user = musicFan();
    tonightAt('Play', '18:00', 'stage');
    tonightAt('Cabaret', '19:00', 'stage');
    tonightAt('Walk', '20:00', 'tours');
    tonightAt('Puppets', '21:00', 'kids');
    tonightAt('Gig', '22:00', 'music');

    $ideas = collect(onPhone($user)->getJson('/api/v1/today')->json('ideas'))->take(4)->values();
    $shown = collect(json_decode(DB::table('list_impressions')->where('subject_key', Pseudonym::for($user))->value('shown'), true));
    $team = fn (int $id) => $shown->first(fn (array $item) => str_starts_with($item['key'], "e{$id}@"))['team'];
    onPhone($user)->postJson('/api/v1/signals', ['signals' => $ideas->map(fn (array $idea, int $at) => ['action' => 'impression', 'surface' => 'today', 'item_type' => 'event', 'item_id' => (int) $idea['id'], 'position' => $at])->all()])->assertStatus(202);
    // They open one idea from their own order.
    $theirs = $ideas->first(fn (array $idea) => $team((int) $idea['id']) === Interleaving::YOURS);
    onPhone($user)->postJson('/api/v1/signals', ['signals' => [['action' => 'detail_viewed', 'surface' => 'event_sheet', 'item_type' => 'event', 'item_id' => (int) $theirs['id'], 'dwell_seconds' => 30]]])->assertStatus(202);

    $this->artisan('personalisation:week', ['--week' => '2026-11-17'])->assertSuccessful();
    $votes = json_decode(DB::table('personalisation_weeks')->value('report'), true)['interleaving'];

    expect($votes)->toMatchArray(['people' => 1, 'prefer_theirs' => 1, 'prefer_usual' => 0, 'level' => 0])
        ->and($votes['says'])->toBe('Too few to say: 1 vote this week; it needs 30 or more.');
});
