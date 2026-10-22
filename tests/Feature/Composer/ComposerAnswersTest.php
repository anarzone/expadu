<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Models\ActiveTrip;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

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

/** Asks Composer as the page does: the message, then the answer to it. Returns the latest reply. */
function askComposer($test, string $text): array
{
    $revision = $test->getJson('/composer/workspace')->json('revision');
    $test->postJson('/composer/workspace', ['revision' => $revision, 'action' => 'message', 'text' => $text])->assertOk();
    $state = $test->postJson('/composer/workspace', ['revision' => $revision + 1, 'action' => 'answer'])->assertOk()->json();

    return collect($state['conversations'][0]['messages'])->last();
}

test('🔒 an answer is worked out from the stored request, never from words the page sends', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    // Nothing asked yet: nothing to answer, and nothing changes.
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'answer'])->assertUnprocessable()
        ->assertJsonPath('refusal.code', 'not_found')->assertJsonPath('workspace.revision', 0);

    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'Hi'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'answer', 'text' => 'What do I need to bring?'])
        ->assertUnprocessable()->assertJsonValidationErrors('text');

    $reply = collect($this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'answer'])->assertOk()
        ->json('conversations.0.messages'))->last();
    expect($reply)->role->toBe('assistant')->request->toBe('around');
});

test('🔒 the checklist is the asker\'s own record, never another account\'s', function () {
    answersCatalogue();
    $owner = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($owner);
    $process = app(ReconcileProcesses::class)->execute($owner, $case->person, 'de-nrw-cologne')[0];
    app(RecordEvidence::class)->execute($owner, $case->person->fresh(), (string) Str::uuid(), ['label' => 'Passport', 'kind' => 'passport',
        'reported_available' => true, 'requirement_refs' => ['fixture.answers.document.1']], 0, (string) Str::uuid());

    $this->actingAs($owner);
    $reply = askComposer($this, 'What do I need to bring?');
    expect($reply)->request->toBe('checklist')->kind->toBe('checklist')
        ->and($reply['record'])->prepared->toBe(0)->total->toBe(2)
        ->href->toBe('/bureaucracy?view=paperwork&process='.rawurlencode($process->occurrence_key))
        ->and(collect($reply['record']['documents'])->pluck('state', 'label')->all())
        ->toBe(['Synthetic passport' => 'You have it · not checked yet', 'Synthetic contract' => 'Still to get']);

    // Someone else asking the same gets their own state (or Paperwork's pointer), never the owner's.
    $this->actingAs(User::factory()->onboarded()->create());
    $reply = json_encode(askComposer($this, 'What do I need to bring?'));
    expect($reply)->not->toContain('You have it')->not->toContain($process->occurrence_key);
});

test('🔒 answering changes no plan', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'appointment', 'date' => $date, 'name' => 'Dentist', 'start' => '10:00', 'end' => '11:00'])->assertOk();
    $before = $this->getJson('/composer/workspace')->json();

    expect(askComposer($this, 'When is my next appointment?')['text'])->toBe('Your next appointment: Dentist, tomorrow at 10:00.');
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
    $reply = askComposer($this, 'Where do I get off?');
    expect($reply)->kind->toBe('trip')->request->toBe('onway')
        ->title->toBe('Get off at Mülheim Wiener Platz at 14:32.')
        ->and($reply['trip'])->link->toBe('trip')
        ->and($reply['trip']['for']['place'])->toBe('Private appointment');

    // Someone else asking the same has no trip under way: nothing of the traveller's comes back.
    $this->actingAs(User::factory()->onboarded()->create());
    $other = json_encode(askComposer($this, 'Where do I get off?'));
    expect($other)->not->toContain('Private appointment')->not->toContain('Wiener Platz')->not->toContain('"trip"');
});
