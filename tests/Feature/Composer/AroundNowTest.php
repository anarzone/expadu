<?php

use App\Composer\Answers\AroundCheck;
use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\User;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Without the AI (owner, 22 Oct 2026), Composer says so and shows what's around you now,
 * from real data only, never a dead end and never a guess at what the words meant. And
 * the check every sentence the AI writes goes through (AroundCheck).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-05 19:05', 'Europe/Berlin'));
    config()->set('services.llm.driver', 'off');
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'temperature' => 9, 'condition' => 'Overcast', 'precipitation' => 0, 'icon' => 'cloudy'];
        }

        public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
        {
            return ['available' => true, 'rain_soon' => true, 'rain_summary' => 'Rain from 22:00 (2h+)'];
        }
    });
});

function whatsUpEvent(string $title, string $venue, string $start, string $end): Event
{
    $event = Event::factory()->create(['title' => $title, 'title_en' => null, 'location_name' => $venue, 'category' => 'music', 'is_curated' => true,
        'starts_at' => CarbonImmutable::parse($start, 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse($end, 'Europe/Berlin')]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [6.93, 50.94, $event->id]);

    return $event;
}

/** The person's own plan for tonight, as the workspace keeps it. */
function whatsUpPlan(User $user, string $name): void
{
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 0, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => 'initial', 'focus' => null, 'changes' => [],
        'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
        'plans' => ['2026-11-05' => ['date' => '2026-11-05', 'slots' => [['id' => 'custom:private', 'name' => $name, 'category' => 'custom',
            'start_at' => '2026-11-05T21:30:00+01:00', 'end_at' => '2026-11-05T22:30:00+01:00']]]],
    ]]);
}

/** Asks as the page does: the message, then Composer's answer to it. */
function whatsUpAsk($test, string $text): array
{
    $revision = $test->getJson('/composer/workspace')->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();

    return collect($test->postJson(route('composer.ask'))->assertOk()->json('conversations.0.messages'))->last();
}

test('🔒 without the AI, Composer says so and shows what’s around you now, with something to do next', function () {
    $user = User::factory()->onboarded()->create();
    whatsUpEvent('Jazz im Stadtgarten', 'Stadtgarten', '2026-11-05 20:00', '2026-11-05 23:00');
    whatsUpPlan($user, 'Late swim');
    $this->actingAs($user);

    $reply = whatsUpAsk($this, 'tell me a joke');

    expect($reply)->kind->toBe('around')->request->toBe('ask')->written->toBe('code')->without_ai->toBe('off')
        ->text->toBe('I can’t answer messages right now, so I can’t answer that. Here’s what’s still on in Cologne tonight.')
        ->and(collect($reply['blocks'])->pluck('type')->all())->toBe(['facts', 'events'])
        ->and(collect($reply['events'])->pluck('title')->all())->toBe(['Jazz im Stadtgarten'])
        ->and(collect($reply['facts'])->map(fn (array $fact): string => "{$fact['label']}: {$fact['value']}")->all())
        ->toBe(['Weather: 9° · Overcast · Rain from 22:00 (2h+)', 'Today 21:30: Late swim'])
        ->and(collect($reply['suggestions'])->pluck('label')->all())->toContain('What’s on tonight?', 'Something indoors');
});

test('🔒 a part whose query fails leaves it out, and the answer is still saved', function () {
    // A failed query must not abort the transaction the answer is saved in (CI, 22 Oct).
    app()->instance(WeatherService::class, new class extends WeatherService
    {
        public function __construct() {}

        public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
        {
            DB::select('select * from no_such_table');

            return [];
        }
    });
    $this->actingAs(User::factory()->onboarded()->create());

    $reply = whatsUpAsk($this, 'hi');

    expect($reply)->kind->toBe('around')->without_ai->toBe('off')->and($reply)->not->toHaveKey('facts');
});

test('🔒 a sentence keeps only the names, numbers and claims its items hold', function (string $sentence, bool $holds) {
    $source = "Thursday 5 November, 19:05, Cologne\n\"Jazz im Stadtgarten\" (music) at Stadtgarten, Neustadt-Nord. today 20:00–23:00, 12,50 €.\nCologne now: 9°, Overcast, Rain from 22:00 (2h+)";

    expect(app(AroundCheck::class)->holds($sentence, $source))->toBe($holds);
})->with([
    'grounded' => ['Jazz im Stadtgarten starts at 20:00 in Neustadt-Nord.', true],
    'weather' => ['It’s 9° and overcast, with rain from 22:00.', true],
    'what it can do' => ['I can’t book tables, but I can plan your evening.', true],
    'an invented place' => ['Or try the Rhine promenade.', false],
    'an invented time' => ['Jazz im Stadtgarten starts at 19:30.', false],
    'an invented price' => ['Jazz im Stadtgarten is free.', false],
    'invented weather' => ['It’s sunny out.', false],
    'a count' => ['There are three concerts tonight.', false],
    'a hedge' => ['Jazz im Stadtgarten is probably busy.', false],
    'the person’s own day' => ['You have a plan at 20:00.', false],
    'a guess at the person’s day' => ['Your swim is later, so go early.', false],
    'their evening' => ['Make the most of your evening.', true],
    'what it can plan' => ['Sorry, I can’t tell jokes. It’s 9° though, and I can plan your day.', true],
    'a name after a full stop' => ['It’s 9°. Rhine Week starts today.', false],
    'a link' => ['See https://example.com for more.', false],
    'access its listing doesn\'t state' => ['Jazz im Stadtgarten is step-free.', false],
    'access it says isn\'t listed' => ['Step-free access isn’t listed for Jazz im Stadtgarten.', true],
    'access it can\'t confirm' => ['I can’t confirm step-free access at Stadtgarten.', true],
    'access no listing states' => ['Neither listing mentions wheelchair access.', true],
    'access claimed beside an unknown' => ['Jazz im Stadtgarten is step-free and its price isn’t listed.', false],
    'access claimed after a but' => ['I can’t confirm the price, but Stadtgarten is step-free.', false],
    'their own hours' => ['For your two hours, Jazz im Stadtgarten starts at 20:00.', false],
    'a two-hour evening' => ['Jazz im Stadtgarten makes a two-hour evening from 20:00.', true],
    'a price written the other way' => ['Jazz im Stadtgarten is €12.50 at 20:00.', true],
    'a price it doesn’t have' => ['Jazz im Stadtgarten is €12.00 at 20:00.', false],
    'a welcome' => ['Welcome to Neustadt-Nord!', true],
    'what it didn’t find' => ['I didn’t find anything free tonight.', true],
    'a free claim beside what it didn’t find' => ['I didn’t find a bar, but Jazz im Stadtgarten is free.', false],
    'wifi it says isn’t listed' => ['Wi-Fi isn’t listed for Stadtgarten.', true],
    'a question back' => ['Which Stadtgarten do you mean?', true],
]);
