<?php

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\Task;
use App\Models\User;
use App\Profile\Applicability;
use App\Profile\ProfileEngine;
use Carbon\CarbonImmutable;

// ── Applicability evaluator (pure) ─────────────────────────────────────

dataset('applicability', [
    'empty applies_if matches everyone' => [null, ['purpose' => 'study'], Applicability::Yes],
    'scalar equality matches' => [[['purpose' => 'study']], ['purpose' => 'study'], Applicability::Yes],
    'scalar equality fails' => [[['purpose' => 'study']], ['purpose' => 'employment'], Applicability::No],
    'list membership matches' => [[['purpose' => ['digital_nomad', 'other']]], ['purpose' => 'other'], Applicability::Yes],
    'list membership fails' => [[['purpose' => ['digital_nomad', 'other']]], ['purpose' => 'study'], Applicability::No],
    'gte matches its boundary' => [[['blue_card_qualifying_months' => ['gte' => 20]]], ['blue_card_qualifying_months' => 20], Applicability::Yes],
    'gte rejects a lower value' => [[['blue_card_qualifying_months' => ['gte' => 20]]], ['blue_card_qualifying_months' => 19], Applicability::No],
    'lte matches its boundary' => [[['blue_card_qualifying_months' => ['lte' => 27]]], ['blue_card_qualifying_months' => 27], Applicability::Yes],
    'in operator matches strictly' => [[['german_level' => ['in' => ['b1', 'b2', 'c1', 'c2']]]], ['german_level' => 'b1'], Applicability::Yes],
    'present true matches non-null' => [[['residence_title_expires_at' => ['present' => true]]], ['residence_title_expires_at' => '2027-01-01'], Applicability::Yes],
    'present true rejects null' => [[['residence_title_expires_at' => ['present' => true]]], ['residence_title_expires_at' => null], Applicability::No],
    'present false matches null' => [[['residence_title_expires_at' => ['present' => false]]], ['residence_title_expires_at' => null], Applicability::Yes],
    'missing value stays unknown for comparisons' => [[['blue_card_qualifying_months' => ['gte' => 20]]], [], Applicability::Unknown],
    'numeric comparison rejects a non-numeric actual value' => [[['blue_card_qualifying_months' => ['gte' => 20]]], ['blue_card_qualifying_months' => 'twenty'], Applicability::No],
    'date age matches an exact month boundary' => [[['family_residence_permit_held_since' => ['at_least_months_ago' => 36]]], ['family_residence_permit_held_since' => '2023-08-03'], Applicability::Yes],
    'date age rejects one day before the month boundary' => [[['family_residence_permit_held_since' => ['at_least_months_ago' => 36]]], ['family_residence_permit_held_since' => '2023-08-04'], Applicability::No],
    'date age range matches a four year old permit' => [[['family_residence_permit_held_since' => ['months_ago_between' => [36, 59]]]], ['family_residence_permit_held_since' => '2022-09-01'], Applicability::Yes],
    'date age range excludes a permit at the five year boundary' => [[['family_residence_permit_held_since' => ['months_ago_between' => [36, 59]]]], ['family_residence_permit_held_since' => '2021-08-03'], Applicability::No],
    'AND within group fails on one condition' => [
        [['purpose' => 'employment', 'citizenship_group' => 'eu']],
        ['purpose' => 'employment', 'citizenship_group' => 'non_eu'],
        Applicability::No,
    ],
    'OR across groups: second group wins' => [
        [['purpose' => 'study'], ['purpose' => 'employment']],
        ['purpose' => 'employment'],
        Applicability::Yes,
    ],
    'null attribute makes verdict unknown' => [
        [['license_country' => ['other']]],
        ['license_country' => null],
        Applicability::Unknown,
    ],
    'definitive failure beats unknown within a group' => [
        [['purpose' => 'study', 'license_country' => ['other']]],
        ['purpose' => 'employment', 'license_country' => null],
        Applicability::No,
    ],
    'yes across groups beats unknown' => [
        [['license_country' => ['other']], ['purpose' => 'study']],
        ['purpose' => 'study', 'license_country' => null],
        Applicability::Yes,
    ],
]);

test('applies_if evaluation is tri-state', function (?array $appliesIf, array $attributes, Applicability $expected) {
    $this->travelTo('2026-08-03 10:00:00');

    expect(Applicability::evaluate($appliesIf, $attributes))->toBe($expected);
})->with('applicability');

test('unknown operator conditions expose the registered fact key', function () {
    $conditions = [['blue_card_qualifying_months' => ['gte' => 20]]];

    expect(Applicability::unknownAttributes($conditions, []))
        ->toBe(['blue_card_qualifying_months']);
});

test('malformed operator conditions fail explicitly', function (mixed $condition) {
    Applicability::evaluate([['blue_card_qualifying_months' => $condition]], [
        'blue_card_qualifying_months' => 22,
    ]);
})->with([
    'explicit null' => null,
    'unsupported operator' => [['gt' => 20]],
    'multiple operators' => [['gte' => 20, 'lte' => 27]],
    'non-numeric comparison operand' => [['gte' => 'twenty']],
    'non-list in operand' => [['in' => 'b1']],
    'non-boolean present operand' => [['present' => 1]],
    'invalid date age operand' => [['at_least_months_ago' => -1]],
    'invalid date age range' => [['months_ago_between' => [60, 36]]],
])->throws(DomainException::class);

test('every persona can reach approved address registration and is offered no unreviewed route', function (array $persona) {
    // Real catalogue, compiled the way a release is. Registration may wait on an
    // answer (needs_information) but must never be ruled out for someone who has
    // arrived, and guidance may only come from reviewed, approved content.
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $catalogue = app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all());
    $attributes = app(ProfileEngine::class)->build(BureaucracyPersonas::userFor($persona))->attributes;
    $values = array_filter([
        'arrival_planned' => false,
        'citizenship_group' => $persona['is_eu'] ? 'eu' : 'non_eu',
        'purpose' => $attributes['purpose'] ?? null,
        'entry_mode' => $persona['entry_mode'],
    ], fn ($value) => $value !== null);

    $assessment = (new AssessPerson)->assess(new AssessmentInput(['values' => $values], [], [], $catalogue, 'de-nrw-cologne', CarbonImmutable::now()))->toArray();
    $registration = collect($assessment['processes'])->firstWhere('definition_id', 'address.registration');

    expect($registration)->not->toBeNull()
        ->and(array_column($registration['variants'], 'assessment'))->each->toBeIn(['needs_information', 'supported_preparation', 'requirements_met'])
        ->and(collect($assessment['processes'])->pluck('variants')->flatten(1)->pluck('id')->filter(
            fn ($id) => Task::where('key', $id)->value('review_status') !== 'approved'
        )->all())->toBe([]);
})->with(fn () => array_map(fn ($persona) => [$persona], BureaucracyPersonas::coverage()));

// ── Teasers ────────────────────────────────────────────────────────────

test('fact-date deadlines use the exact registered fact date and pause when it is missing', function () {
    $user = User::factory()->onboarded()->create();
    $renewal = Task::factory()->create([
        'deadline_type' => 'fact_date',
        'deadline_days' => null,
        'deadline_fact_key' => 'residence_title_expires_at',
    ]);

    expect($renewal->computeDeadlineFor($user, [
        'current_residence_title' => 'blue_card',
        'residence_title_expires_at' => '2027-03-18',
    ])?->toDateString())->toBe('2027-03-18')
        ->and($renewal->computeDeadlineFor($user, []))->toBeNull()
        ->and($renewal->computeDeadlineFor($user, [
            'residence_title_expires_at' => '2027-02-31',
        ]))->toBeNull();
});

test('D-visa application deadlines still use visa expiry exactly', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => '2026-08-01']);
    $application = Task::factory()->create([
        'deadline_type' => 'permit_window',
        'deadline_days' => 90,
    ]);

    expect($application->computeDeadlineFor($user, [
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2026-12-14',
    ])?->toDateString())->toBe('2026-12-14')
        ->and($application->computeDeadlineFor($user, [
            'entry_mode' => 'd_visa',
            'visa_expires_at' => null,
        ]))->toBeNull();
});

test('user:reset-journey cannot pretend a dossier is reset by clearing only the old profile', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'profile_attributes' => ['license_country' => 'other', 'child_born_at' => '2026-05-01'],
    ]);
    $this->actingAs($user);
    app(PathGenerator::class)->ensure($user); // materialise tasks + progress
    app(EnsureAccountHolder::class)->dossier($user);
    expect($user->userTasks()->count())->toBeGreaterThan(0);
    $before = $user->fresh()->getRawOriginal();
    $tasks = $user->userTasks()->get()->toArray();
    $facts = $user->bureaucracyCase->facts()->get()->toArray();

    $this->artisan('user:reset-journey', ['email' => $user->email, '--force' => true])
        ->expectsOutputToContain('read-only persona preview')
        ->assertFailed();

    $user->refresh();
    expect($user->getRawOriginal())->toBe($before)
        ->and($user->userTasks()->get()->toArray())->toBe($tasks)
        ->and($user->bureaucracyCase->facts()->get()->toArray())->toBe($facts);
});

// ── Visa expiry anchoring ──────────────────────────────────────────────
