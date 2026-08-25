<?php

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

/**
 * A document can name who it is for.
 *
 * The spine exists as six near-identical cards per concept because there was no
 * way to say "birth certificates, only if children are moving with you" on a
 * single card. `branch:` labels a document but shows it to everyone, which is
 * right for the two routes of a driving-licence exchange and wrong for a family
 * document a single person should never be handed.
 *
 * Only a definite No hides a document. An unanswered question leaves it on the
 * list: showing one somebody turns out not to need costs a moment, hiding one
 * they did need costs them the appointment.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();
});

function documentsFor(User $user, string $taskKey): array
{
    $response = test()->actingAs($user)->get('/bureaucracy');
    $response->assertOk();

    $cards = collect($response->viewData('page')['props']['tasks'])
        ->flatten(1)
        ->filter(fn ($card) => is_array($card) && ($card['key'] ?? null) === $taskKey);

    return collect($cards->first()['documents_required'] ?? [])
        ->map(fn ($doc) => is_array($doc) ? ($doc['label'] ?? null) : $doc)
        ->filter()
        ->values()
        ->all();
}

function seedScopedDocuments(string $taskKey): void
{
    Task::query()->where('key', $taskKey)->update([
        'documents_required' => [
            ['label' => 'Passport'],
            ['label' => 'Birth certificates for children', 'applies_if' => [['purpose' => 'family']]],
        ],
    ]);
}

it('hides a document from someone it definitely does not apply to', function () {
    $task = Task::query()->where('key', 'core.anmeldung')->firstOrFail();
    seedScopedDocuments('core.anmeldung');

    $nomad = User::factory()->onboarded()->create([
        'situation' => 'digital_nomad',
        'is_eu' => false,
    ]);
    UserTask::factory()->create(['user_id' => $nomad->id, 'task_id' => $task->id]);

    expect(documentsFor($nomad, 'core.anmeldung'))->toBe(['Passport']);
});

it('keeps a document whose condition cannot be judged yet', function () {
    $task = Task::query()->where('key', 'core.anmeldung')->firstOrFail();
    Task::query()->where('key', 'core.anmeldung')->update([
        'documents_required' => [
            ['label' => 'Passport'],
            // Nothing in this profile answers it, so the verdict is Unknown.
            ['label' => 'Proof of the thing we never asked about', 'applies_if' => [['never_asked_attribute' => 'yes']]],
        ],
    ]);

    $nomad = User::factory()->onboarded()->create([
        'situation' => 'digital_nomad',
        'is_eu' => false,
    ]);
    UserTask::factory()->create(['user_id' => $nomad->id, 'task_id' => $task->id]);

    expect(documentsFor($nomad, 'core.anmeldung'))
        ->toBe(['Passport', 'Proof of the thing we never asked about']);
});

it('never leaks the condition to the browser', function () {
    $task = Task::query()->where('key', 'core.anmeldung')->firstOrFail();
    seedScopedDocuments('core.anmeldung');

    $nomad = User::factory()->onboarded()->create([
        'situation' => 'digital_nomad',
        'is_eu' => false,
    ]);
    UserTask::factory()->create(['user_id' => $nomad->id, 'task_id' => $task->id]);

    $response = $this->actingAs($nomad)->get('/bureaucracy');
    $card = collect($response->viewData('page')['props']['tasks'])
        ->flatten(1)
        ->first(fn ($c) => is_array($c) && ($c['key'] ?? null) === 'core.anmeldung');

    foreach ($card['documents_required'] as $doc) {
        expect($doc)->not->toHaveKey('applies_if');
    }
});
