<?php

use App\Models\Task;

/**
 * Owner review of the driving licence task: "There are different branches and
 * below, documents to bring. Are these the documents for the appointment? If
 * yes, does the translation apply to all branches? If not, it should not be
 * there. On what basis were these documents chosen?"
 *
 * The schema was the problem. `how_to_steps` carried the branch structure while
 * `documents_required` and `links` were flat, so route-specific paperwork read
 * as everyone's, and two links sat in a list with nothing saying which route
 * each belonged to.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

it('scopes route-specific driving licence paperwork to its route', function () {
    $task = Task::query()->where('key', 'shared.driving_licence')->firstOrFail();

    $byLabel = collect($task->documents_required)
        ->mapWithKeys(fn (array $doc): array => [$doc['label'] => $doc['branch'] ?? null]);

    // Only the exam route needs these; everything else applies to both routes.
    expect($byLabel['Vision test certificate + first-aid course certificate'])->toBe('Exam route')
        ->and($byLabel['Translation of the licence — by ADAC or a sworn translator'])->toBeNull()
        ->and($byLabel['Passport or ID'])->toBeNull();

    // The scoping must not live in prose any more.
    expect(collect($task->documents_required)->pluck('label')->implode(' '))
        ->not->toContain('Exam route only:');
});

it('attaches each conversion link to the route it describes', function () {
    $task = Task::query()->where('key', 'shared.driving_licence')->firstOrFail();

    $links = collect($task->how_to_steps)->mapWithKeys(
        fn (array $step): array => [$step['title'] => $step['link'] ?? null],
    );

    expect($links['Anlage 11 country: test-free exchange'])->toContain('00834')
        ->and($links['All other countries: exams required'])->toContain('00836')
        // A flat list gave the reader no way to tell which link was theirs.
        ->and($task->links)->toBe([]);
});

/**
 * A branch badge that names a route the task does not describe is worse than no
 * badge: the reader is told a document is conditional and cannot find out on
 * what.
 */
it('never labels a document with a branch the task does not describe', function () {
    $offenders = [];

    foreach (Task::query()->where('is_published', true)->get() as $task) {
        // A step declares the short name of the route it describes; a document
        // may only point at one of those names. Matching on prose titles was
        // guesswork — this is exact.
        $routes = collect($task->how_to_steps ?? [])
            ->pluck('branch')
            ->filter()
            ->all();

        foreach ((array) ($task->documents_required ?? []) as $doc) {
            $branch = is_array($doc) ? ($doc['branch'] ?? null) : null;

            if ($branch !== null && ! in_array($branch, $routes, true)) {
                $offenders[] = "{$task->key}: document branch [{$branch}] names no route on this task";
            }
        }
    }

    expect($offenders)->toBe([]);
});
