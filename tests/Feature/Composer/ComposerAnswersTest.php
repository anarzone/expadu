<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Models\ActiveTrip;
use App\Models\Task;
use App\Models\User;
use App\Privacy\ProcessingPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExternalProcessingFixtures;

/*
 * The person's own records in Composer's answers: their checklist, their next appointment
 * and their trip are the AI's to show, never to read (OwnCards), and always the asker's own.
 */

beforeEach(function () {
    config()->set('services.llm', [
        'driver' => 'anthropic', 'base_url' => 'https://processor.example.test', 'model' => 'claude-haiku-5-5', 'key' => 'synthetic',
        'processor_name' => 'Synthetic processor', 'processor_privacy_url' => 'https://processor.example.test/privacy', 'prompt_version' => 'test.1',
    ]);
    // The AI's turns, in the order the messages are asked.
    app()->instance('answers.turns', $turns = new SplQueue);
    Http::fake(['processor.example.test/*' => fn () => Http::response($turns->dequeue())]);
});

/** The AI's turns for one message: this tool call, then an answer that shows its card. */
function answersTurns(string $tool, array $input, string $card): array
{
    $turn = fn (string $name, array $in): array => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => $name, 'input' => $in === [] ? (object) [] : $in]], 'usage' => ['input_tokens' => 300, 'output_tokens' => 40]];

    return [$turn($tool, $input), $turn('answer', ['sentences' => [['text' => 'Here it is.', 'uses' => [$card]]], 'show' => [$card]])];
}

/** One reviewed fixture card that applies to everyone, with two documents to bring. */
function answersCatalogue(): void
{
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.answers', 'title' => 'EU Blue Card', 'type' => 'task',
        'applies_if' => [], 'depends_on' => [], 'deadline_type' => 'none', 'links' => [], 'how_to_steps' => [],
        'documents_required' => ['Synthetic passport', 'Synthetic contract']])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => $task->key, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]))->id, null);
}

/** Asks Composer as the page does: the message, then its answer, the AI using `$tool`. Returns the answer. */
function askComposer($test, string $text, string $tool = 'my_next', array $input = [], string $card = 'next'): array
{
    foreach (answersTurns($tool, $input, $card) as $turn) {
        app('answers.turns')->enqueue($turn);
    }
    $revision = $test->getJson('/composer/workspace')->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();
    $state = $test->postJson(route('composer.ask'), ['processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])->assertOk()->json();

    return collect($state['conversations'][0]['messages'])->last(fn (array $m): bool => ($m['request'] ?? null) === 'ask');
}

test('🔒 an answer is worked out from the stored message, never from words the page sends', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    foreach (answersTurns('weather', [], 'weather') as $turn) {
        app('answers.turns')->enqueue($turn);
    }

    // Nothing asked yet: nothing to answer, and nothing changes.
    $this->postJson(route('composer.ask'))->assertUnprocessable()->assertJsonValidationErrors('text');
    expect($this->getJson('/composer/workspace')->json('revision'))->toBe(0);

    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'Hi'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'answer'])->assertUnprocessable();
    $this->postJson(route('composer.ask'), ['text' => 'PAGE-WORDS-6102', 'processing' => ExternalProcessingFixtures::acceptance(ProcessingPurpose::ComposerAssist)])->assertOk();

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'Message: \\"Hi\\"'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'PAGE-WORDS-6102'));
});

test('🔒 the Paperwork record is the asker\'s own: never another account\'s, and without their permission neither sent nor shown', function () {
    answersCatalogue();
    $owner = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($owner);
    $process = app(ReconcileProcesses::class)->execute($owner, $case->person, 'de-nrw-cologne')[0];
    app(RecordEvidence::class)->execute($owner, $case->person->fresh(), (string) Str::uuid(), ['label' => 'Passport', 'kind' => 'passport',
        'reported_available' => true, 'requirement_refs' => ['fixture.answers.document.1']], 0, (string) Str::uuid());

    $this->actingAs($owner);
    $reply = askComposer($this, 'What do I need to bring?', 'paperwork', [], 'pw');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'Synthetic passport') || str_contains($request->body(), 'EU Blue Card'));
    // Without their permission for this message, the card asking for it is the whole answer
    // (coordinator, 23 Oct 2026): nothing of the record is sent or shown.
    expect(collect($reply['blocks'])->pluck('type')->all())->toBe(['permission'])
        ->and($reply['blocks'][0]['purpose'])->toBe('composer_paperwork')
        ->and(json_encode($reply))->not->toContain('You have it')->not->toContain('Synthetic contract')->not->toContain($process->occurrence_key);

    // Someone else asking the same gets their own state (or Paperwork's pointer), never the owner's.
    $this->actingAs(User::factory()->onboarded()->create());
    $reply = json_encode(askComposer($this, 'What do I need to bring?', 'paperwork', [], 'pw'));
    expect($reply)->not->toContain('You have it')->not->toContain($process->occurrence_key);
});

test('🔒 answering changes no plan', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'appointment', 'date' => $date, 'name' => 'Dentist', 'start' => '10:00', 'end' => '11:00'])->assertOk();
    $before = $this->getJson('/composer/workspace')->json();

    $reply = askComposer($this, 'When is my next appointment?', 'my_next', ['appointment' => true]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'Dentist'));
    expect($reply['blocks'][0]['text'])->toBe('Your next appointment: Dentist, tomorrow at 10:00.');
    foreach (['Cancel tomorrow', 'Move the dentist to 15:00', 'blorp fizz'] as $text) {
        askComposer($this, $text);
    }

    $after = $this->getJson('/composer/workspace')->json();
    expect($after['plans'])->toBe($before['plans'])->and($after['changes'])->toBe($before['changes']);
});

/** A trip under way (Travel's "I'm on my way"): a walk, tram 4, and the walk there. */
function tripUnderWay(User $user): void
{
    $at = fn (int $minutes) => now('Europe/Berlin')->addMinutes($minutes);
    $leg = fn (string $mode, ?string $line, string $from, string $to, int $start, int $end) => [
        'mode' => $mode, 'line' => $line, 'headsign' => $line ? 'Köln Schlebusch' : null,
        'from' => ['name' => $from, 'lat' => 50.93, 'lng' => 6.94], 'to' => ['name' => $to, 'lat' => 50.96, 'lng' => 7.0],
        'depart_at' => $at($start)->toIso8601String(), 'arrive_at' => $at($end)->toIso8601String(),
        'depart_time' => $at($start)->format('H:i'), 'arrive_time' => $at($end)->format('H:i'), 'duration_min' => $end - $start,
    ];
    ActiveTrip::query()->create([
        'user_id' => $user->id,
        'journey' => ['mode' => 'transit', 'depart_at' => $at(0)->toIso8601String(), 'arrive_at' => $at(38)->toIso8601String(),
            'depart_time' => $at(0)->format('H:i'), 'arrive_time' => $at(38)->format('H:i'), 'duration_min' => 38, 'transfers' => 0, 'legs' => [
                $leg('walk', null, 'Your location', 'Köln Neumarkt', 0, 4),
                $leg('tram', '4', 'Köln Neumarkt', 'Köln Mülheim Wiener Platz', 4, 32),
                $leg('walk', null, 'Köln Mülheim Wiener Platz', 'Private destination', 32, 38),
            ]],
        'destination' => ['name' => 'Private destination', 'lat' => 50.96, 'lng' => 7.0],
        'target' => ['id' => 'appointment:1', 'title' => 'Private appointment', 'label' => 'Mülheim', 'kind' => 'appointment',
            'official' => true, 'start_at' => $at(50)->toIso8601String()],
        'started_at' => $at(0),
    ]);
}

test('🔒 on the way, Composer answers from the asker\'s own trip, never another account\'s', function () {
    // A fixed clock: the trip's times and the expected one are read at different moments,
    // which can fall either side of a minute.
    $this->travelTo(CarbonImmutable::parse('2026-10-21 14:00', 'Europe/Berlin'));
    $traveller = User::factory()->onboarded()->create();
    tripUnderWay($traveller);

    $this->actingAs($traveller);
    $reply = askComposer($this, 'Where do I get off?', 'my_trip', ['ask' => 'stop'], 'trip');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'Private appointment') || str_contains($request->body(), 'Wiener Platz'));
    expect($reply)->kind->toBe('trip')->request->toBe('ask')
        ->title->toBe('Get off at Mülheim Wiener Platz at 14:32.')
        ->and($reply['trip'])->link->toBe('trip')
        ->and($reply['trip']['for']['place'])->toBe('Private appointment');

    // Someone else asking the same has no trip under way: nothing of the traveller's comes back.
    $this->actingAs(User::factory()->onboarded()->create());
    $other = json_encode(askComposer($this, 'Where do I get off?', 'my_trip', ['ask' => 'stop'], 'trip'));
    expect($other)->not->toContain('Private appointment')->not->toContain('Wiener Platz')->not->toContain('"trip"');
});
