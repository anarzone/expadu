<?php

use App\Home\TodayDiscovery;
use App\Models\Event;
use App\Models\ListImpression;
use App\Models\User;
use App\Personalisation\Pseudonym;
use App\Personalisation\TodayTaste;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The lists Today shows, logged to measure whether an order helps (personalisation plan, item
 * 14): only for people who opted in, under the pseudonym, never from the ranker's own read,
 * erased with the ledger and pruned after 90 days.
 */
beforeEach(function () {
    // Monday 26 October 2026 at noon in Cologne, nothing planned.
    $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00', 'Europe/Berlin'));
    $this->user = User::factory()->onboarded()->create();
    $this->events = collect(['18:00', '19:00', '20:00'])->map(fn (string $time) => Event::factory()->create([
        'title' => "At {$time}", 'starts_at' => CarbonImmutable::parse("2026-10-26 {$time}", 'Europe/Berlin')->utc(), 'ends_at' => null, 'venue_id' => null,
    ]));
    $this->keys = $this->events->map(fn (Event $event) => 'e'.$event->id.'@'.$event->starts_at->getTimestamp())->all();
});

function listsShown(User $user): array
{
    return DB::table('list_impressions')->where('subject_key', Pseudonym::for($user))->get()->all();
}

function listsOn(User $user): User
{
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true]])->assertOk();

    return $user->fresh();
}

test('without the opt-in nothing is logged, and the ranker\'s own read never logs', function () {
    app(TodayDiscovery::class)->for($this->user, shown: true);
    expect(DB::table('list_impressions')->count())->toBe(0);

    $user = listsOn($this->user);
    app(TodayDiscovery::class)->for($user);
    expect(DB::table('list_impressions')->count())->toBe(0);
});

test('a list shown keeps the rule order and its layouts; the same list again counts on its row', function () {
    $user = listsOn($this->user);
    app(TodayDiscovery::class)->for($user, shown: true);
    app(TodayDiscovery::class)->for($user, shown: true);

    $rows = listsShown($user);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->surface)->toBe('today_events')
        ->and($rows[0]->policy)->toBe('rule')
        ->and($rows[0]->version)->toBe(TodayDiscovery::RULES)
        ->and((int) $rows[0]->seen_count)->toBe(2)
        ->and(json_decode($rows[0]->baseline, true))->toBe($this->keys)
        ->and(array_column(json_decode($rows[0]->shown, true), 'key'))->toBe($this->keys)
        ->and(array_column(json_decode($rows[0]->shown, true), 'layout'))->toBe(['poster', 'poster', 'compact'])
        ->and(DB::table('list_impressions')->where('subject_key', (string) $user->id)->exists())->toBeFalse();
});

test('the AI\'s order is logged as its own list, beside the rule order it changed', function () {
    $user = listsOn($this->user);
    $reversed = array_reverse($this->keys);
    Cache::put(TodayTaste::orderKey($user->id), ['events' => $reversed, 'places' => [], 'why' => [], 'version' => 'haiku-1'], now()->endOfDay());

    app(TodayDiscovery::class)->for($user, shown: true);

    $row = listsShown($user)[0];
    expect($row->policy)->toBe('haiku')
        ->and($row->version)->toBe('haiku-1')
        ->and(json_decode($row->baseline, true))->toBe($this->keys)
        ->and(array_column(json_decode($row->shown, true), 'key'))->toBe($reversed);
});

test('turning personalisation off or deleting the account erases the lists shown', function () {
    $user = listsOn($this->user);
    app(TodayDiscovery::class)->for($user, shown: true);
    expect(listsShown($user))->toHaveCount(1);

    $this->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();
    expect(listsShown($user))->toHaveCount(0);

    $user = listsOn($user);
    app(TodayDiscovery::class)->for($user, shown: true);
    $subject = Pseudonym::for($user);
    $this->delete('/settings/profile', ['password' => 'password'])->assertRedirect();

    expect(User::find($user->id))->toBeNull()
        ->and(DB::table('list_impressions')->where('subject_key', $subject)->count())->toBe(0);
});

test('lists last seen over 90 days ago are pruned', function () {
    $user = listsOn($this->user);
    app(TodayDiscovery::class)->for($user, shown: true);
    DB::table('list_impressions')->update(['last_seen_at' => now()->subDays(91)->toIso8601String()]);

    $this->artisan('model:prune', ['--model' => [ListImpression::class]])->assertSuccessful();

    expect(listsShown($user))->toHaveCount(0);
});
