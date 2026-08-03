<?php

use App\Enums\Situation;
use App\Models\Task;
use App\Models\User;
use App\Profile\ProfileEngine;
use App\Profile\TicketAdvice;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;

dataset('branches', [
    'non-EU employee' => ['non_eu_employee', null, 'non_eu_employee', false],
    'EU employee' => ['eu_employee', null, 'eu_employee', true],
    'student (EU)' => ['student', true, 'student', true],
    'student (non-EU)' => ['student', false, 'student', false],
    'freelancer (EU)' => ['freelancer', true, 'freelancer', true],
    'freelancer (non-EU)' => ['freelancer', false, 'freelancer', false],
    'family reunification' => ['family_reunification', null, 'family_reunification', false],
    'digital nomad' => ['digital_nomad', true, 'core', true],
    'other (unknown citizenship)' => ['other', null, 'core', false],
]);

test('branch and EU resolution per situation', function (string $situation, ?bool $isEu, string $expectedBranch, bool $expectedEu) {
    $user = User::factory()->make([
        'situation' => $situation,
        'is_eu' => $isEu,
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-05-01',
    ]);

    $profile = app(ProfileEngine::class)->build($user);

    expect($profile->bureaucracyBranch)->toBe($expectedBranch);
    expect($profile->isEu)->toBe($expectedEu);
})->with('branches');

dataset('tickets', [
    'student gets Semesterticket' => ['student', TicketAdvice::SemesterTicket],
    'non-EU employee asks for JobTicket' => ['non_eu_employee', TicketAdvice::JobTicketAsk],
    'EU employee asks for JobTicket' => ['eu_employee', TicketAdvice::JobTicketAsk],
    'freelancer gets Deutschlandticket' => ['freelancer', TicketAdvice::DeutschlandTicket],
    'nomad gets Deutschlandticket' => ['digital_nomad', TicketAdvice::DeutschlandTicket],
]);

test('ticket advice per situation', function (string $situation, TicketAdvice $expected) {
    $user = User::factory()->make(['situation' => $situation, 'is_eu' => false]);

    expect(app(ProfileEngine::class)->build($user)->ticketAdvice)->toBe($expected);
})->with('tickets');

test('default areas expand the veedel to its bezirk', function () {
    $user = User::factory()->make(['situation' => 'student', 'is_eu' => true, 'veedel' => 'Nippes']);

    $areas = app(ProfileEngine::class)->build($user)->defaultAreas;

    expect($areas)->toContain('Nippes')
        ->toContain('Riehl')
        ->toContain('Weidenpesch')
        ->not->toContain('Ehrenfeld');
});

test('missing veedel yields empty default areas', function () {
    $user = User::factory()->make(['situation' => 'student', 'is_eu' => true, 'veedel' => null]);

    expect(app(ProfileEngine::class)->build($user)->defaultAreas)->toBe([]);
});

test('missing situation falls back to the core branch', function () {
    $user = User::factory()->make(['situation' => null]);

    $profile = app(ProfileEngine::class)->build($user);

    expect($profile->situation)->toBe(Situation::Other);
    expect($profile->bureaucracyBranch)->toBe('core');
});

dataset('path refinements', [
    'blue card refines the branch' => ['non_eu_employee', 'non_eu_employee_blue_card', 'non_eu_employee_blue_card'],
    'standard records but keeps the base' => ['non_eu_employee', 'non_eu_employee', 'non_eu_employee'],
    'family of-German variant' => ['family_reunification', 'family_reunification_of_german', 'family_reunification_of_german'],
    'gewerbe refines freelancer' => ['freelancer', 'freelancer_gewerbe', 'freelancer_gewerbe'],
    'path from another branch is ignored' => ['non_eu_employee', 'freelancer_gewerbe', 'non_eu_employee'],
    'unknown path is ignored' => ['freelancer', 'freelancer_unicorn', 'freelancer'],
    'paths never apply to unambiguous branches' => ['eu_employee', 'non_eu_employee_blue_card', 'eu_employee'],
]);

test('stored bureaucracy_path refines the branch only when valid', function (string $situation, string $path, string $expectedBranch) {
    $user = User::factory()->make([
        'situation' => $situation,
        'is_eu' => false,
        'bureaucracy_path' => $path,
    ]);

    expect(app(ProfileEngine::class)->build($user)->bureaucracyBranch)->toBe($expectedBranch);
})->with('path refinements');

test('path options are offered only for ambiguous branches', function () {
    $engine = app(ProfileEngine::class);

    $nonEu = User::factory()->make(['situation' => 'non_eu_employee']);
    expect($engine->pathOptionsFor($nonEu))->toHaveKeys([
        'non_eu_employee', 'non_eu_employee_blue_card', 'non_eu_employee_chancenkarte',
    ]);

    $euEmployee = User::factory()->make(['situation' => 'eu_employee']);
    expect($engine->pathOptionsFor($euEmployee))->toBe([]);
});

test('days since arrival computes from arrival date', function () {
    $user = User::factory()->make([
        'situation' => 'student',
        'is_eu' => true,
        'arrival_date' => now()->subDays(9)->toDateString(),
    ]);

    expect(app(ProfileEngine::class)->build($user)->daysSinceArrival())->toBe(9);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function task4ApprovedRule(string $key, array $overrides = []): array
{
    return array_replace([
        'key' => $key,
        'title' => 'Approved conditional rule',
        'jurisdiction' => 'de-nrw-cologne',
        'review_status' => 'approved',
        'reviewed_by' => 'expadu_content_owner',
        'content_version' => '2026-08-04.1',
        'source_verification' => 'dual_source',
        'verified_at' => '2026-08-04',
        'legal_sources' => [
            [
                'kind' => 'primary',
                'label' => 'AufenthG',
                'url' => 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
            ],
            [
                'kind' => 'implementation',
                'label' => 'Stadt Köln',
                'url' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
            ],
        ],
    ], $overrides);
}

/**
 * @param  list<array<string, mixed>>  $tasks
 * @return array{directory: string, file: string}
 */
function task4WriteCatalogue(array $tasks): array
{
    $directory = sys_get_temp_dir().'/bureaucracy_operators_'.uniqid();
    mkdir($directory);
    $file = $directory.'/catalogue.yaml';
    file_put_contents($file, Yaml::dump([
        'situation' => 'core',
        'tasks' => $tasks,
    ], 8, 2));

    return ['directory' => $directory, 'file' => $file];
}

/** @param array{directory: string, file: string} $catalogue */
function task4DeleteCatalogue(array $catalogue): void
{
    if (is_file($catalogue['file'])) {
        unlink($catalogue['file']);
    }

    if (is_dir($catalogue['directory'])) {
        rmdir($catalogue['directory']);
    }
}

test('approved imports accept registered condition operators and fact-date deadlines', function () {
    $catalogue = task4WriteCatalogue([
        task4ApprovedRule('operator.gte', ['applies_if' => ['blue_card_qualifying_months' => ['gte' => 20]]]),
        task4ApprovedRule('operator.lte', ['applies_if' => ['blue_card_qualifying_months' => ['lte' => 27]]]),
        task4ApprovedRule('operator.in', ['applies_if' => ['german_level' => ['in' => ['b1', 'b2', 'c1', 'c2']]]]),
        task4ApprovedRule('operator.present', ['applies_if' => ['residence_title_expires_at' => ['present' => true]]]),
        task4ApprovedRule('operator.at-least-months-ago', ['applies_if' => ['family_residence_permit_held_since' => ['at_least_months_ago' => 36]]]),
        task4ApprovedRule('operator.months-ago-between', ['applies_if' => ['family_residence_permit_held_since' => ['months_ago_between' => [36, 59]]]]),
        task4ApprovedRule('operator.scalar', ['applies_if' => ['case_goal' => 'blue_card']]),
        task4ApprovedRule('operator.legacy-list', ['applies_if' => ['case_goal' => ['blue_card', 'renew_current_title']]]),
        task4ApprovedRule('deadline.fact-date', [
            'deadline_type' => 'fact_date',
            'deadline_fact_key' => 'residence_title_expires_at',
        ]),
    ]);

    try {
        $exitCode = Artisan::call('bureaucracy:import-tasks', ['file' => $catalogue['file']]);

        $this->assertSame(0, $exitCode, Artisan::output());
        expect(Task::whereIn('key', [
            'operator.gte',
            'operator.lte',
            'operator.in',
            'operator.present',
            'operator.at-least-months-ago',
            'operator.months-ago-between',
            'operator.scalar',
            'operator.legacy-list',
            'deadline.fact-date',
        ])->count())->toBe(9)
            ->and(Task::where('key', 'deadline.fact-date')->firstOrFail()->deadline_type->value)->toBe('fact_date')
            ->and(Task::where('key', 'deadline.fact-date')->value('deadline_fact_key'))
            ->toBe('residence_title_expires_at');
    } finally {
        task4DeleteCatalogue($catalogue);
    }
});

test('approved imports reject malformed or unregistered condition operands atomically', function (array $condition) {
    $catalogue = task4WriteCatalogue([
        task4ApprovedRule('operator.valid-sibling'),
        task4ApprovedRule('operator.invalid', ['applies_if' => $condition]),
    ]);

    try {
        $exitCode = Artisan::call('bureaucracy:import-tasks', ['file' => $catalogue['file']]);

        expect($exitCode)->toBe(1)
            ->and(Task::whereIn('key', ['operator.valid-sibling', 'operator.invalid'])->exists())->toBeFalse();
    } finally {
        task4DeleteCatalogue($catalogue);
    }
})->with([
    'explicit null' => [['case_goal' => null]],
    'unknown fact' => [['unregistered_fact' => 'anything']],
    'invalid scalar enum value' => [['case_goal' => 'not_a_goal']],
    'invalid in enum value' => [['german_level' => ['in' => ['b1', 'native']]]],
    'invalid date value' => [['family_residence_permit_held_since' => '2026-02-31']],
    'multiple operators' => [['blue_card_qualifying_months' => ['gte' => 20, 'lte' => 27]]],
    'unsupported operator' => [['blue_card_qualifying_months' => ['gt' => 20]]],
    'comparison on enum fact' => [['german_level' => ['gte' => 20]]],
    'non-integer comparison operand' => [['weekly_work_hours' => ['gte' => '20']]],
    'non-boolean present operand' => [['residence_title_expires_at' => ['present' => 1]]],
    'date age operator on an integer fact' => [['weekly_work_hours' => ['at_least_months_ago' => 36]]],
    'negative date age' => [['family_residence_permit_held_since' => ['at_least_months_ago' => -1]]],
    'descending date age range' => [['family_residence_permit_held_since' => ['months_ago_between' => [60, 36]]]],
    'short date age range' => [['family_residence_permit_held_since' => ['months_ago_between' => [36]]]],
]);

test('fact-date imports require a registered date fact', function (?string $factKey) {
    $task = task4ApprovedRule('deadline.invalid-fact', [
        'deadline_type' => 'fact_date',
    ]);

    if ($factKey !== null) {
        $task['deadline_fact_key'] = $factKey;
    }

    $catalogue = task4WriteCatalogue([$task]);

    try {
        $exitCode = Artisan::call('bureaucracy:import-tasks', ['file' => $catalogue['file']]);

        expect($exitCode)->toBe(1)
            ->and(Task::where('key', 'deadline.invalid-fact')->exists())->toBeFalse();
    } finally {
        task4DeleteCatalogue($catalogue);
    }
})->with([
    'missing key' => null,
    'unknown key' => 'unknown_date',
    'non-date fact' => 'german_level',
]);
