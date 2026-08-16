<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\PathGenerator;
use App\Models\Task;
use App\Models\User;
use App\Profile\Applicability;
use App\Profile\ProfileEngine;
use Illuminate\Support\Facades\Artisan;

/**
 * The catalogue must yield a complete, dependency-valid path for EVERY expat
 * persona the engine can represent. `bureaucracy:coverage --full --fail-on-gap`
 * runs the real ProfileEngine + PathGenerator across the whole persona
 * cross-product (situation × citizenship × entry_mode × housing × licence ×
 * life-event) and asserts the structural invariants:
 *   - every persona reaches the Anmeldung root;
 *   - every non-EU permit-bearing persona reaches a residence-permit task;
 *   - no applicable task depends_on a task that does not apply (blocked forever);
 *   - every published task is reachable by at least one persona (no dead cards);
 *   - every Unknown task has a teaser question (never silently hidden).
 * A future YAML edit that breaks any of these fails here with a named reason.
 */
it('yields a complete, gap-free path for every persona', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $exitCode = Artisan::call('bureaucracy:coverage', ['--full' => true, '--fail-on-gap' => true]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('✓ No gaps.');
});

/**
 * The audit sweep must not depend on `--full`. Reachability is only meaningful
 * over the whole modifier cross-product: life-event tasks stay dormant until
 * their trigger fires, and the licence task needs a licence-bearing persona, so
 * a narrow sweep reported five healthy tasks as dead cards — and `--fail-on-gap`
 * counted them, meaning the gate failed on phantom gaps whenever `--full` was
 * omitted. Both invocations must now agree.
 */
it('audits the full modifier sweep whether or not --full is passed', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $exitCode = Artisan::call('bureaucracy:coverage', ['--fail-on-gap' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('✓ No gaps.')
        ->and($output)->not->toContain('Unreachable tasks')
        ->and($output)->not->toContain('Silently-hidden tasks');
});

/**
 * The teaser-hygiene check resolves waited-on attributes against each persona's
 * real attribute bag. Passing an empty bag marked branch-defining attributes
 * (purpose, citizenship_group, permit_track, business_type, sponsor) as
 * "unknown" even though every real user always has them — reporting
 * `shared.driving_licence` as silently hidden when its only open attribute,
 * `license_country`, does have a teaser question.
 */
it('does not report branch-defining attributes as missing teaser questions', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    Artisan::call('bureaucracy:coverage');

    expect(Artisan::output())
        ->toContain('Bureaucracy coverage')
        ->not->toContain('shared.driving_licence')
        ->not->toContain('waits on `purpose`')
        ->not->toContain('waits on `citizenship_group`')
        ->not->toContain('waits on `sponsor`');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function task3PublishUnreachable(string $key, array $overrides): Task
{
    return Task::factory()->create([
        'key' => $key,
        'title' => "Fixture — {$key}",
        'is_published' => true,
        'review_status' => 'legacy',
        ...$overrides,
    ]);
}

/**
 * The teaser-hygiene check must judge a task that IS unreachable by the same
 * rule. This fixture only ever goes Unknown — `license_country` is null for some
 * swept personas and never matches otherwise — so it survives the reachability
 * guard and reaches the attribute comparison itself. Its other two attributes
 * are branch-defining and always known, so the only genuinely open attribute is
 * `license_country`, which has a teaser question: no orphan, gate stays green.
 * Resolved against an empty bag instead, the two branch attributes would read as
 * unknown, have no teaser question, and fail the gate.
 */
it('judges teaser hygiene against the persona attribute bag, not an empty one', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    task3PublishUnreachable('fixture.licence_gated', [
        'applies_if' => [[
            'purpose' => 'employment',
            'citizenship_group' => 'non_eu',
            'license_country' => 'unobtainable_value',
        ]],
    ]);

    $exitCode = Artisan::call('bureaucracy:coverage', ['--fail-on-gap' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('waits on `purpose`')
        ->and($output)->not->toContain('waits on `citizenship_group`');
});

/**
 * Guard against the fix above going too far: silencing the false positives must
 * not silence a genuine dead card. A task gated on a value no persona can hold
 * is unreachable and unaskable, and must both print and fail the gate.
 */
it('still fails the gate on a genuinely unreachable task', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    task3PublishUnreachable('fixture.unreachable', [
        'applies_if' => [['purpose' => 'employment', 'citizenship_group' => 'martian']],
    ]);

    $exitCode = Artisan::call('bureaucracy:coverage', ['--fail-on-gap' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('Unreachable tasks')
        ->toContain('fixture.unreachable');
});

/**
 * Invariant 5 must be enforced, not merely printed: a task that goes Unknown on
 * an attribute with no teaser question can never be asked about, so it would be
 * silently hidden from every user forever.
 */
it('still fails the gate on a silently-hidden task', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    task3PublishUnreachable('fixture.silently_hidden', [
        'applies_if' => [['unmapped_attribute' => 'yes']],
    ]);

    $exitCode = Artisan::call('bureaucracy:coverage', ['--fail-on-gap' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('Silently-hidden tasks')
        ->toContain('fixture.silently_hidden')
        ->toContain('waits on `unmapped_attribute`');
});

/**
 * Regression guard for the trade-registration dependency: Gewerbeanmeldung must
 * never hard-depend on the §21 residence permit, which is non_eu_only and gated
 * to d_visa/visa_free. Depending on it blocks the trade registration forever for
 * EU traders and for non-EU founders who already hold a permit.
 */
it('keeps Gewerbeanmeldung reachable for EU and already-permitted traders', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $engine = app(ProfileEngine::class);
    $paths = app(PathGenerator::class);
    $gewerbe = Task::query()->where('key', 'gw.gewerbeanmeldung')->firstOrFail();

    $trader = fn (bool $isEu): User => new User([
        'situation' => 'freelancer',
        'is_eu' => $isEu,
        'bureaucracy_path' => 'freelancer_gewerbe',
        'arrival_date' => now()->subDays(10)->toDateString(),
        'veedel' => 'Altstadt-Nord',
        'profile_attributes' => ['entry_mode' => 'has_permit', 'housing_status' => 'long_term'],
    ]);

    expect($paths->applicability($gewerbe, $engine->build($trader(true))))->toBe(Applicability::Yes)
        ->and($paths->applicability($gewerbe, $engine->build($trader(false))))->toBe(Applicability::Yes);
});

it('does not publish known stale bureaucracy links or figures', function () {
    $catalogue = collect(glob(database_path('seeders/data/bureaucracy/*.yaml')))
        ->map(fn (string $file): string => file_get_contents($file))
        ->implode("\n");

    expect($catalogue)
        ->not->toContain('stadt-koeln.de/service/produkt/anmeldung-einer-wohnung')
        ->not->toContain('rundfunkbeitrag.de/en/')
        ->not->toContain('Up to €934/month');
});

/**
 * `coverage_scope: universal` bypasses case matching entirely (CaseMatcher
 * collects it whenever applies_if is satisfied, regardless of the matched case),
 * which is right for cross-cutting rules and wrong for anything conditional.
 *
 * `shared.*` qualifies: every rule applies in all branches and none is
 * trigger-gated. `le.*` must NOT, because CaseMatcher and CasePlanComposer do
 * not read `trigger_event` — a universal life-event rule would surface "reserve
 * a Kita place" to users who never recorded a birth, losing the dormancy
 * PathGenerator guarantees. `core.*` stays case-scoped too: it is the digital
 * nomad / other branch, not a universal spine.
 */
it('scopes only genuinely cross-cutting rules as universal', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $scopes = fn (string $prefix): array => Task::query()
        ->where('is_published', true)
        ->get()
        ->filter(fn (Task $task): bool => str_starts_with((string) $task->key, $prefix))
        ->pluck('coverage_scope')
        ->unique()
        ->values()
        ->all();

    expect($scopes('shared.'))->toBe(['universal'])
        ->and($scopes('le.'))->toBe(['case'])
        ->and($scopes('core.'))->toBe(['case']);

    // The invariant behind all of the above: nothing conditional may ride the
    // universal channel while that channel ignores trigger_event.
    expect(Task::query()
        ->where('is_published', true)
        ->where('coverage_scope', 'universal')
        ->whereNotNull('trigger_event')
        ->pluck('key')
        ->all())->toBe([]);
});

it('keeps every investigated case rule authoritative and every scenario in the QA roster', function () {
    $this->travelTo('2026-08-03 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $expectedRuleKeys = [
        'case.bc.first_application.prepare',
        'case.bc.first_application.submit',
        'case.bc.settlement.track_21_months',
        'case.bc.verify_status_source',
        'case.family.first_permit.prepare',
        'case.family.first_permit.sponsor_pending_review',
        'case.family.independent_after_separation',
        'case.family.register_address',
        'case.family.renew.continuing_household',
        'case.family.settlement.general_coming_up',
        'case.family.settlement.spouse_18c_option',
    ];

    expect(Task::query()->authoritative()->whereIn('key', $expectedRuleKeys)->orderBy('key')->pluck('key')->all())
        ->toBe($expectedRuleKeys)
        ->and(collect(BureaucracyPersonas::caseScenarios())->pluck('key')->all())->toBe([
            'case-blue-card-first',
            'case-family-sponsor-pending',
            'case-blue-card-b1-12',
            'case-spouse-18c-three-years',
            'case-family-renewal-four-years',
            'case-unsupported-title',
        ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function task3ApproveCoreAnmeldung(array $overrides = []): Task
{
    $task = Task::query()->where('key', 'core.anmeldung')->firstOrFail();

    $task->forceFill(array_replace([
        'jurisdiction' => 'de-nrw-cologne',
        'review_status' => 'approved',
        'reviewed_by' => 'expadu_content_owner',
        'content_version' => '2026-08-03.1',
        'source_verification' => 'dual_source',
        'verified_at' => today()->subDays(30),
        'review_due_at' => today()->addDays(30),
        'effective_from' => today()->subYear(),
        'effective_to' => today()->addYear(),
        'legal_sources' => [
            [
                'kind' => 'primary',
                'label' => '§ 17 BMG',
                'url' => 'https://www.gesetze-im-internet.de/bmg_2013/__17.html',
            ],
            [
                'kind' => 'implementation',
                'label' => 'Stadt Köln — Anmeldung',
                'url' => 'https://www.stadt-koeln.de/service/produkte/00415/index.html',
            ],
        ],
    ], $overrides))->save();

    return $task->refresh();
}

it('fails coverage when an approved rule has incomplete source metadata', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    task3ApproveCoreAnmeldung(['legal_sources' => []]);

    expect(Task::query()->authoritative()->where('key', 'core.anmeldung')->exists())->toBeFalse();

    $exitCode = Artisan::call('bureaucracy:coverage', ['--full' => true, '--fail-on-gap' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('core.anmeldung')
        ->toContain('SOURCE REVIEW');
});

it('fails coverage when approved review or effective dates are outside their valid window', function (string $invalidState) {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $overrides = match ($invalidState) {
        'missing_review_due' => ['review_due_at' => null],
        'past_review_due' => ['review_due_at' => today()->subDay()],
        'future_effective_from' => ['effective_from' => today()->addDay()],
        'past_effective_to' => ['effective_to' => today()->subDay()],
    };

    task3ApproveCoreAnmeldung($overrides);

    $exitCode = Artisan::call('bureaucracy:coverage', ['--full' => true, '--fail-on-gap' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('core.anmeldung')
        ->toContain('SOURCE REVIEW');
})->with([
    'missing review due date' => 'missing_review_due',
    'past review due date' => 'past_review_due',
    'future effective date' => 'future_effective_from',
    'past effective end date' => 'past_effective_to',
]);

it('treats a review due today as current and keeps complete approved rules gap-free', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    task3ApproveCoreAnmeldung(['review_due_at' => today()]);

    $this->artisan('bureaucracy:coverage', ['--full' => true, '--fail-on-gap' => true])
        ->assertSuccessful();

    expect(Task::query()->authoritative()->where('key', 'core.anmeldung')->exists())->toBeTrue()
        ->and(Task::query()->authoritative()->where('review_status', 'legacy')->exists())->toBeFalse();
});
