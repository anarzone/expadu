<?php

use App\Enums\Situation;
use App\Models\User;
use App\Profile\ProfileEngine;
use App\Profile\TicketAdvice;

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

test('days since arrival computes from arrival date', function () {
    $user = User::factory()->make([
        'situation' => 'student',
        'is_eu' => true,
        'arrival_date' => now()->subDays(9)->toDateString(),
    ]);

    expect(app(ProfileEngine::class)->build($user)->daysSinceArrival())->toBe(9);
});
