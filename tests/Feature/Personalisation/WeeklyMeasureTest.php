<?php

use App\Models\Event;
use App\Models\User;
use App\Personalisation\Pseudonym;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\SignalSurface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * A week of Today's ideas in totals: what the iPhone showed, and what was opened, saved or
 * planned, by where and why it stood. Nothing per person is kept.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00', 'Europe/Berlin'));
});

function eveningEvent(string $title, string $time, string $category): Event
{
    return Event::factory()->create([
        'title' => $title, 'category' => $category,
        'starts_at' => CarbonImmutable::parse("2026-11-17 {$time}", 'Europe/Berlin')->utc(),
        'ends_at' => CarbonImmutable::parse("2026-11-17 {$time}", 'Europe/Berlin')->addHour()->utc(),
    ]);
}

function phone(User $user)
{
    return test()->withToken($user->createToken('iPhone')->plainTextToken);
}

function weekReport(): array
{
    test()->artisan('personalisation:week', ['--week' => '2026-11-17'])->assertSuccessful();

    return json_decode(DB::table('personalisation_weeks')->where('week', '2026-11-16')->value('report'), true);
}

test('each idea on screen counts once, with what was done with it, by where and why it stood', function () {
    $user = User::factory()->onboarded()->create();
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true], 'sixteen_or_older' => true])->assertOk();
    $user = $user->fresh();
    $play = eveningEvent('Play', '18:00', 'stage');
    $cabaret = eveningEvent('Cabaret', '19:00', 'stage');
    $walk = eveningEvent('Walk', '20:00', 'tours');
    eveningEvent('Puppets', '21:00', 'kids');
    $gig = eveningEvent('Gig', '22:00', 'music');
    phone($user)->putJson('/api/v1/me/picks', ['kinds' => ['music'], 'done' => true])->assertOk();

    // Today as the phone gets it: the gig they picked first, then the play, a New for you third.
    $ideas = collect(phone($user)->getJson('/api/v1/today')->assertOk()->json('ideas'))->take(4);
    expect($ideas->pluck('title')->all())->toBe(['Gig', 'Play', 'Cabaret', 'Walk']);
    $seen = $ideas->values()->map(fn (array $idea, int $at) => ['action' => 'impression', 'surface' => 'today', 'item_type' => 'event', 'item_id' => (int) $idea['id'], 'position' => $at])->all();
    phone($user)->postJson('/api/v1/signals', ['signals' => [...$seen, ...$seen]])->assertStatus(202);
    phone($user)->postJson('/api/v1/signals', ['signals' => [['action' => 'detail_viewed', 'surface' => 'event_sheet', 'item_type' => 'event', 'item_id' => $gig->id, 'dwell_seconds' => 25]]])->assertStatus(202);
    app(SignalRecorder::class)->record($user, SignalAction::Saved, SignalSurface::Today, SignalItemType::Event, $play->id);

    $report = weekReport();

    expect($report['people'])->toBe(1)->and($report['ideas_shown'])->toBe(4)
        ->and($report['enough'])->toBeFalse()
        ->and($report['says'])->toStartWith('Too few to say: 1 person')
        ->and($report['positions'][0])->toMatchArray(['position' => 1, 'shown' => 1, 'opened' => 1, 'acted' => 1])
        ->and($report['positions'][1])->toMatchArray(['position' => 2, 'shown' => 1, 'saved' => 1, 'acted' => 1])
        // The gig was 5th in the usual order: the arrangement brought it into view, and it was opened.
        ->and($report['brought_into_view'])->toMatchArray(['shown' => 1, 'acted' => 1])
        ->and($report['there_anyway'])->toMatchArray(['shown' => 3, 'acted' => 1])
        ->and($report['new_for_you'])->toMatchArray(['shown' => 1, 'acted' => 0])
        ->and($report['picked_kind'])->toMatchArray(['shown' => 1, 'acted' => 1])
        ->and(array_keys($report['policies']))->toBe(['rule rules-2+arranged-2'])
        // Totals only: no one's key in what's kept.
        ->and(DB::table('personalisation_weeks')->value('report'))->not->toContain(Pseudonym::for($user));
});

test('🔒 someone with personalisation off counts for nothing', function () {
    $user = User::factory()->onboarded()->create();
    $gig = eveningEvent('Gig', '22:00', 'music');

    phone($user)->getJson('/api/v1/today')->assertOk();
    phone($user)->postJson('/api/v1/signals', ['signals' => [['action' => 'impression', 'surface' => 'today', 'item_type' => 'event', 'item_id' => $gig->id, 'position' => 0]]])->assertStatus(202);

    expect(weekReport())->toMatchArray(['people' => 0, 'ideas_shown' => 0, 'policies' => []]);
});
