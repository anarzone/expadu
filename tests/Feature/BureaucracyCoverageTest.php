<?php

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

    $this->artisan('bureaucracy:coverage', ['--full' => true, '--fail-on-gap' => true])
        ->assertExitCode(0);
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
