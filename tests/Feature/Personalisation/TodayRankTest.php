<?php

use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use App\Personalisation\PersonalisationWording;
use App\Personalisation\SignalItemType;
use App\Personalisation\SignalRecorder;
use App\Personalisation\TasteFacts;
use App\Personalisation\TodayRanker;
use App\Personalisation\TodayTaste;
use App\Privacy\ProcessingPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Today put in order by the AI (owner, 26 Oct 2026: "AI should be used for personalization"): it
 * orders only what Today shows, from their taste and recent interests; a why holds only when it's
 * theirs and about the pick, else Today keeps its own order; nothing is hidden; only with
 * personalisation on under its second wording, each ordering through the gate under its own permission.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 15:00', 'Europe/Berlin'));
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Anthropic PBC', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    $this->user = User::factory()->onboarded()->create();
    $this->candidates = ['events' => [
        ['id' => 'e1@1', 'name' => 'Orgelkonzert', 'kind' => 'Concert', 'area' => 'Altstadt-Nord', 'start' => '19:00'],
        ['id' => 'e2@1', 'name' => 'Latte Art Night', 'kind' => 'Café', 'area' => 'Ehrenfeld', 'start' => '18:00'],
    ], 'places' => [
        ['id' => 'p1', 'name' => 'Brauhaus Sion', 'kind' => 'Bar', 'area' => 'Altstadt-Nord', 'start' => null],
        ['id' => 'p2', 'name' => 'Café Wohnraum', 'kind' => 'Café', 'area' => 'Nippes', 'start' => null],
        ['id' => 'p3', 'name' => 'Ehrenfeld Kaffee', 'kind' => 'Café', 'area' => 'Ehrenfeld', 'start' => null],
    ]];
    $this->facts = ['categories' => [['name' => 'café', 'level' => 'strong']], 'veedels' => [['name' => 'Ehrenfeld', 'level' => 'some']], 'dayparts' => ['evening'], 'week' => null, 'signals' => 20];
    $this->sent = [];
    $this->next = ['events' => [], 'places' => []];
    // One fake; each test says what the model answers next.
    $this->reply = function (array $events, array $places): void {
        $this->next = ['events' => $events, 'places' => $places];
    };
    Http::fake(['processor.example.test/*' => function (Request $request) {
        $this->sent[] = $request->data();

        return Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'order', 'input' => $this->next]],
            'usage' => ['input_tokens' => 400, 'output_tokens' => 80]]);
    }]);
});

function rankingOn(User $user): void
{
    test()->actingAs($user)->putJson('/user-settings', ['settings' => ['personalisation' => true]])->assertOk();
    $user->load('userSetting');
}

test('their taste puts the café in Ehrenfeld first, with its why; what it left out keeps its place after', function () {
    rankingOn($this->user);
    ($this->reply)([['id' => 'e2@1', 'why' => 'You lean to cafés in Ehrenfeld.']], [['id' => 'p3', 'why' => 'You lean to cafés in Ehrenfeld.'], ['id' => 'p2']]);

    $order = app(TodayRanker::class)->rankFrom($this->user, $this->candidates, $this->facts, CarbonImmutable::now('Europe/Berlin'));

    expect($order)->toMatchArray(['events' => ['e2@1', 'e1@1'], 'places' => ['p3', 'p2', 'p1'],
        'why' => ['e2@1' => 'You lean to cafés in Ehrenfeld.', 'p3' => 'You lean to cafés in Ehrenfeld.']])
        // Today serves it for the day.
        ->and(app(TodayTaste::class)->order($this->user))->toMatchArray(['places' => ['p3', 'p2', 'p1']])
        ->and(BureaucracyProcessingConsent::query()->where('actor_id', $this->user->id)->where('purpose', 'today_rank')->pluck('state')->all())->toBe(['completed']);
});

test('a why that isn’t theirs and Today keeps its own order; one about another pick is left out', function () {
    rankingOn($this->user);

    // Not in their taste: museums, a number, a reason of its own.
    ($this->reply)([['id' => 'e1@1', 'why' => 'Two museums nearby make this a great evening.']], []);
    expect(app(TodayRanker::class)->rankFrom($this->user, $this->candidates, $this->facts, CarbonImmutable::now('Europe/Berlin')))->toBeNull()
        ->and(app(TodayTaste::class)->order($this->user))->toBeNull();

    // Theirs, but about another pick: a bar in Altstadt-Nord isn't a café in Ehrenfeld. The order stands, without that why.
    ($this->reply)([], [['id' => 'p1', 'why' => 'You lean to cafés in Ehrenfeld.']]);
    expect(app(TodayRanker::class)->rankFrom($this->user, $this->candidates, $this->facts, CarbonImmutable::now('Europe/Berlin')))
        ->toMatchArray(['places' => ['p1', 'p2', 'p3'], 'why' => []]);
});

test('an event named without its start counts while only one start of it is listed', function () {
    $events = [
        ['id' => 'e1@1', 'name' => 'Orgelkonzert', 'kind' => 'Concert', 'area' => 'Altstadt-Nord', 'start' => '17:00'],
        ['id' => 'e1@2', 'name' => 'Orgelkonzert', 'kind' => 'Concert', 'area' => 'Altstadt-Nord', 'start' => '19:00'],
        ['id' => 'e2@1', 'name' => 'Latte Art Night', 'kind' => 'Café', 'area' => 'Ehrenfeld', 'start' => '18:00'],
    ];
    $context = ['day' => '2026-11-11 15:00', 'facts' => TasteFacts::forModel($this->facts), 'recent' => ['categories' => [], 'areas' => []], 'candidates' => ['events' => $events, 'places' => []]];
    $reply = ['content' => [['type' => 'tool_use', 'name' => 'order', 'input' => ['events' => [['id' => 'e1'], ['id' => 'e2', 'why' => 'You lean to cafés in Ehrenfeld.']], 'places' => []]]]];

    // e2 has one start listed; e1 has two, so a bare e1 names neither.
    expect(app(TodayRanker::class)->compose($context, fn () => $reply))
        ->toMatchArray(['events' => ['e2@1', 'e1@1', 'e1@2'], 'why' => ['e2@1' => 'You lean to cafés in Ehrenfeld.']]);
});

test('nothing goes to the AI without personalisation on under its second wording', function () {
    expect(app(TodayRanker::class)->rankFrom($this->user, $this->candidates, $this->facts, CarbonImmutable::now('Europe/Berlin')))->toBeNull();

    // On before 26 Oct 2026: not until they turn it on again.
    $this->user->userSetting()->updateOrCreate(['user_id' => $this->user->id], ['settings' => ['personalisation' => true]]);
    expect(app(TodayRanker::class)->rankFrom($this->user->fresh(), $this->candidates, $this->facts, CarbonImmutable::now('Europe/Berlin')))->toBeNull();
    Http::assertNothingSent();

    rankingOn($this->user);
    $this->putJson('/user-settings', ['settings' => ['personalisation' => false]])->assertOk();
    expect(app(TodayRanker::class)->rankFrom($this->user->fresh(), $this->candidates, $this->facts, CarbonImmutable::now('Europe/Berlin')))->toBeNull();
    Http::assertNothingSent();
});

test('the notice names everything a ranking sends; stops, Paperwork and their words never go', function () {
    rankingOn($this->user);
    app(SignalRecorder::class)->topic($this->user, 'cafe', 'Ehrenfeld');
    app(SignalRecorder::class)->transit($this->user, SignalItemType::Stop, 'Neumarkt');
    ($this->reply)([], []);

    app(TodayRanker::class)->rankFrom($this->user, $this->candidates, $this->facts, CarbonImmutable::now('Europe/Berlin'));

    $disclosure = ProcessingPurpose::TodayRank->disclosure();
    $notice = implode(' ', $disclosure['notice']);
    $content = $this->sent[0]['messages'][0]['content'];
    $parts = array_map(fn (string $line): string => match (true) {
        str_starts_with($line, 'Now: ') => 'the_day',
        str_starts_with($line, 'They lean to: ') => 'your_taste',
        str_starts_with($line, 'Lately they looked at: ') => 'recent_interests',
        in_array($line, ['Events:', 'Places:'], true) || preg_match('/^[ep][\d@]+: /', $line) === 1 => 'todays_candidates',
        default => 'unknown:'.$line,
    }, explode("\n", $content));

    expect(array_diff(array_unique($parts), $disclosure['payload_categories']))->toBe([])
        ->and(collect(array_unique($parts))->every(fn (string $part): bool => str_contains($notice, ProcessingPurpose::TODAY_WORDS[$part])))->toBeTrue()
        ->and($content)->not->toContain('Neumarkt')->not->toContain('Paperwork')
        ->and($disclosure['scope'])->toBe('until_you_turn_it_off')
        ->and(PersonalisationWording::CURRENT)->toBe(2);
});
