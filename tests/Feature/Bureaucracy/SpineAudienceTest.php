<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Models\Task;
use App\Profile\Applicability;
use App\Profile\ProfileEngine;
use Symfony\Component\Yaml\Yaml;

/**
 * The merged Anmeldung card, read by the people it is for.
 *
 * Six near-identical cards became one. The point of the merge is that the
 * shared 90% gets reviewed once instead of six times — but only if each person
 * still reads their own branch's sentences and is handed their own paperwork.
 * A merge that quietly flattened those differences would have made the card
 * cheaper to approve and worse to follow.
 */
/**
 * The proposal is NOT in the catalogue. Merging the six cards would delete
 * core.anmeldung, the one copy that is approved, and downgrade the two branches
 * that currently have a verified Anmeldung — so it waits for the owner rather
 * than shipping. Loading it here keeps it verified rather than merely written:
 * these assertions fail if the proposal ever stops carrying a branch's
 * sentences or starts handing everyone the family's paperwork.
 */
beforeEach(function () {
    // The real catalogue, so "the proposal is not in it" means something.
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();

    $proposal = Yaml::parseFile(base_path('docs/bureaucracy-gaps/proposed-spine-anmeldung.yaml'));
    $card = $proposal['tasks'][0];

    $this->spine = new Task([
        'key' => $card['key'],
        'title' => $card['title'],
        'description' => $card['description'],
        'description_variants' => $card['description_variants'],
        'documents_required' => $card['documents_required'],
    ]);
});

function audienceFor(string $personaKey): array
{
    $persona = collect(BureaucracyPersonas::demo())->firstWhere('key', $personaKey);

    return app(ProfileEngine::class)
        ->build(BureaucracyPersonas::userFor($persona))
        ->attributes;
}

function spineDocumentsFor(string $personaKey): array
{
    $attributes = audienceFor($personaKey);

    return collect(test()->spine->documents_required)
        ->filter(fn (array $doc): bool => ! isset($doc['applies_if'])
            || Applicability::evaluate($doc['applies_if'], $attributes) !== Applicability::No)
        ->pluck('label')
        ->all();
}

/**
 * Whitespace-normalised, so an assertion is about the sentence rather than
 * about where the YAML block scalar happened to wrap it.
 */
function spineDescriptionFor(string $personaKey): string
{
    $description = (string) test()->spine->descriptionFor(audienceFor($personaKey));

    return trim((string) preg_replace('/\s+/', ' ', $description));
}

it('keeps every branch sentence the six cards used to carry', function () {
    expect(spineDescriptionFor('eu-employee'))->toContain('freedom of movement covers you')
        ->and(spineDescriptionFor('neu-student'))->toContain("can't enrol at university")
        ->and(spineDescriptionFor('family-neu'))->toContain('including children')
        ->and(spineDescriptionFor('neu-freelancer'))->toContain('doubly important')
        ->and(spineDescriptionFor('neu-employee-dvisa'))->toContain('must appear in person');
});

it('does not read someone else\'s paragraph to them', function () {
    // An EU employee is not non-EU and has no family, so neither paragraph is
    // theirs. Merging six cards must not turn one person's card into everyone's.
    $eu = spineDescriptionFor('eu-employee');

    expect($eu)->not->toContain('including children')
        ->and($eu)->not->toContain("can't enrol at university")
        ->and($eu)->not->toContain('finalise your residence permit');
});

it('stacks the paragraphs of someone who is more than one thing', function () {
    // A non-EU parent is both, and needs both.
    $family = spineDescriptionFor('family-neu');

    expect($family)->toContain('must appear in person')
        ->and($family)->toContain('including children')
        // ...on top of the statement everybody gets.
        ->and($family)->toContain('§17 BMG');
});

it('hands each person only their own paperwork', function () {
    expect(spineDocumentsFor('eu-employee'))->toBe([
        'Passport or EU ID card',
        'Wohnungsgeberbescheinigung (Cologne form, signed by landlord/main tenant)',
    ]);

    // The family documents are the ones a single person must never be shown —
    // and the ones a family must never be left to discover at the counter.
    expect(spineDocumentsFor('family-neu'))
        ->toContain('Marriage or partnership certificate')
        ->toContain('Birth certificates for children moving with you')
        ->toContain('ID documents of ALL persons moving in');

    expect(spineDocumentsFor('neu-employee-dvisa'))
        ->toContain('Residence documents you already hold')
        ->not->toContain('Birth certificates for children moving with you');
});

it('is nowhere near a user until the owner puts it there', function () {
    // It lives under docs/, which the importer does not read. Merging it in
    // would delete core.anmeldung — the ONE approved copy — and leave every
    // branch, including the two that have a verified Anmeldung today, with
    // none. That trade is the owner's to make, not a side effect of tooling.
    expect(Task::query()->where('key', 'spine.anmeldung')->exists())->toBeFalse()
        ->and(file_exists(database_path('seeders/data/bureaucracy/spine.yaml')))->toBeFalse()
        ->and(Task::authoritative()->where('key', 'core.anmeldung')->exists())->toBeTrue();
});
