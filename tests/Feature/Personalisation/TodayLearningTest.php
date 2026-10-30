<?php

use App\Composer\Answers\EventAnswers;
use App\Composer\Assistant\AssistantTools;
use App\Composer\CandidateRepository;
use App\Enums\LocationSource;
use App\Models\User;
use App\Personalisation\PersonalisationWording;
use App\Personalisation\Pseudonym;
use App\Personalisation\SignalAction;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\TodayTaste;
use App\Privacy\Export\AccountData;
use App\Services\LocationContext;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Today learns from what people do (owner, 26 Oct 2026: "Go as proposed"), under personalisation's
// second wording only: Composer's topics, Travel's stops and lines, Today's "Needs you". Never
// their words, Paperwork or where they are; erased with the account; nothing personal below the threshold.

function learnOn(User $user, ?int $wording = PersonalisationWording::CURRENT): void
{
    $user->userSetting()->updateOrCreate(['user_id' => $user->id], ['settings' => ['personalisation' => true, ...($wording ? [PersonalisationWording::SETTING => $wording] : [])]]);
    $user->load('userSetting');
}

/** @return list<array<string, mixed>> */
function learned(User $user): array
{
    return DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->orderBy('id')->get(['action', 'surface', 'item_type', 'category', 'veedel', 'context'])
        ->map(fn (object $row): array => (array) $row)->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 12:00', 'Europe/Berlin'));
    $this->user = User::factory()->onboarded()->create();
    $this->signals = app(SignalRecorder::class);
});

test('nothing is learned without the opt-in, or under its first wording', function () {
    $record = function (): void {
        $this->signals->topic($this->user, 'cafe', 'Ehrenfeld');
        $this->signals->transit($this->user, SignalItemType::Stop, 'Neumarkt');
        $this->signals->need($this->user, SignalAction::Tapped, 'disruption', '0123456789abcdef');
    };

    $record();
    expect(learned($this->user))->toBe([]);

    // On before 26 Oct 2026: places and events only, until they turn it on again.
    learnOn($this->user, null);
    $record();
    expect(learned($this->user))->toBe([])->and(PersonalisationWording::current($this->user))->toBeFalse();

    learnOn($this->user);
    $record();
    expect(array_column(learned($this->user), 'item_type'))->toBe(['topic', 'stop', 'need']);
});

test('turning it on keeps the current wording, set by the server alone; off drops it', function () {
    $this->actingAs($this->user)->putJson('/user-settings', ['settings' => ['personalisation' => true, PersonalisationWording::SETTING => 9], 'sixteen_or_older' => true])->assertOk();
    expect($this->user->fresh()->setting(PersonalisationWording::SETTING))->toBe(PersonalisationWording::CURRENT);

    // Another setting saved later can't move it.
    $this->putJson('/user-settings', ['settings' => ['share_location' => false, PersonalisationWording::SETTING => 1]])->assertOk();
    expect($this->user->fresh()->setting(PersonalisationWording::SETTING))->toBe(PersonalisationWording::CURRENT)
        ->and($this->getJson('/user-settings')->json('settings.personalisation_current'))->toBeTrue();

    $this->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();
    expect($this->user->fresh()->setting(PersonalisationWording::SETTING))->toBeNull();
});

test('never their words, Paperwork or where they are', function () {
    learnOn($this->user);
    $this->user->places()->create(['emoji' => '🏠', 'name' => 'Venloer Straße 210', 'address' => 'Venloer Straße 210', 'category' => 'home', 'lat' => 50.947, 'lng' => 6.916, 'sort_order' => 0]);
    $tools = new AssistantTools($this->user, CarbonImmutable::now(), new LocationContext(50.947, 6.916, LocationSource::Live),
        app(CandidateRepository::class), app(EventAnswers::class), app(WeatherService::class));

    // A search with a place they're near and an event topic in their own words: only the kind and the Veedel named.
    $tools->run('search_places', ['kinds' => ['cafe', 'not_a_kind'], 'area' => 'Ehrenfeld', 'near' => 'Venloer Straße 210']);
    $tools->run('search_events', ['topic' => 'my visa appointment nerves', 'day' => 'today']);
    $this->signals->need($this->user, SignalAction::Tapped, 'paperwork', '0123456789abcdef');

    $rows = learned($this->user);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['action' => 'searched', 'surface' => 'composer', 'item_type' => 'topic', 'category' => 'cafe', 'veedel' => 'Ehrenfeld']);
    $stored = json_encode($rows);
    expect($stored)->not->toContain('Venloer')->not->toContain('visa')->not->toContain('50.94')->not->toContain('paperwork');
});

test('a Today item counts as shown once a day; deleting the account erases it all', function () {
    learnOn($this->user);
    $this->actingAs($this->user)->postJson('/api/signals/needs', ['action' => 'shown', 'id' => '0123456789abcdef', 'kind' => 'disruption'])->assertStatus(202);
    $this->postJson('/api/signals/needs', ['action' => 'shown', 'id' => '0123456789abcdef', 'kind' => 'disruption'])->assertStatus(202);
    $this->postJson('/api/signals/needs', ['action' => 'dismissed', 'id' => '0123456789abcdef', 'kind' => 'disruption'])->assertStatus(202);
    // Only the id and kind: a line of text is refused.
    $this->postJson('/api/signals/needs', ['action' => 'tapped', 'id' => 'Line 13 disrupted', 'kind' => 'disruption'])->assertUnprocessable();

    expect(array_column(learned($this->user), 'action'))->toBe(['impression', 'dismissed']);

    $subject = Pseudonym::for($this->user);
    $this->user->delete();
    expect(DB::table('user_signals')->where('subject_key', $subject)->count())->toBe(0);
});

test('the read API says nothing personal below the threshold, then ranks what they looked at', function () {
    learnOn($this->user);
    $this->signals->topic($this->user, 'cafe', 'Ehrenfeld');
    $this->signals->transit($this->user, SignalItemType::Stop, 'Neumarkt');
    expect(app(TodayTaste::class)->for($this->user))->toMatchArray(['personal' => false, 'categories' => [], 'stops' => []]);

    $this->signals->topic($this->user, 'cafe', 'Ehrenfeld');
    $this->signals->topic($this->user, 'bar', 'Nippes');
    $this->signals->transit($this->user, SignalItemType::Stop, 'Neumarkt');
    $this->signals->transit($this->user, SignalItemType::Stop, 'Ebertplatz');
    $this->signals->transit($this->user, SignalItemType::Line, '13');

    $taste = app(TodayTaste::class)->for($this->user);
    expect($taste)->toMatchArray(['personal' => true, 'categories' => ['cafe', 'bar'], 'areas' => ['Ehrenfeld', 'Nippes'], 'stops' => ['Neumarkt', 'Ebertplatz'], 'lines' => ['13']])
        ->and(app(TodayTaste::class)->stops($this->user, 1))->toBe(['Neumarkt']);
});

test('the account export says each learned signal in words', function () {
    learnOn($this->user);
    $this->signals->topic($this->user, 'cafe', 'Ehrenfeld');
    $this->signals->transit($this->user, SignalItemType::Stop, 'Neumarkt');
    $this->signals->need($this->user, SignalAction::Dismissed, 'disruption', '0123456789abcdef');

    $signals = json_decode(app(AccountData::class)->files($this->user)['personalisation.json'], true)['data']['signals'];
    expect(array_column($signals, 'what'))->toEqualCanonicalizing([
        'Composer looked up: cafe in Ehrenfeld',
        'Travel stop you picked: Neumarkt',
        'Today’s “Needs you” item (disruption): dismissed',
    ]);
});
