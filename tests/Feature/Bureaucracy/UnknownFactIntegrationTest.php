<?php

use App\Bureaucracy\Cases\CaseAttributes;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Bureaucracy\PathGenerator;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\Task;
use App\Models\User;
use App\Profile\Applicability;

test('bootstrapping an unanswered profile cannot confirm inferred citizenship or a chosen route', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'student',
        'is_eu' => null,
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'profile_attributes' => [],
    ]);

    $case = app(LegacyFactBootstrapper::class)->bootstrap($user);
    expect($case->facts()->whereIn('key', ['citizenship_group', 'permit_track'])->count())->toBe(0);
    expect(app(CaseAttributes::class)->for($case)['citizenship_group'])->toBeNull();
});

test('both legacy checklist and case matching read the same confirmed title', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee', 'is_eu' => false,
        'bureaucracy_path' => 'non_eu_employee_blue_card', 'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create();
    BureaucracyCaseFact::factory()->for($case, 'case')->create([
        'key' => 'current_residence_title', 'value' => 'settlement_permit_9',
        'state' => 'confirmed', 'confirmed_at' => now(), 'reconfirm_at' => now()->addYear(),
        'superseded_at' => null,
    ]);

    $caseAttributes = app(CaseAttributes::class)->for($case);
    $checklistAttributes = app(PathGenerator::class)->ensure($user)->attributes;

    expect($checklistAttributes['current_residence_title'] ?? null)->toBe('settlement_permit_9')
        ->and($checklistAttributes['current_residence_title'])->toBe($caseAttributes['current_residence_title'])
        ->and($checklistAttributes['permit_track'])->toBeNull();
});

test('retired answers do not reappear from a legacy profile fallback', function () {
    $user = User::factory()->onboarded()->create([
        'profile_attributes' => ['current_residence_title' => 'blue_card'],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create();
    BureaucracyCaseFact::factory()->for($case, 'case')->create([
        'key' => 'current_residence_title', 'value' => 'blue_card',
        'state' => 'superseded', 'confirmed_at' => now()->subDay(), 'superseded_at' => now(),
    ]);

    expect(app(CaseAttributes::class)->for($case)['current_residence_title'])->toBeNull();
    expect(app(PathGenerator::class)->ensure($user)->attributes['current_residence_title'])->toBeNull();
});

test('a legacy task cannot infer the business classification from a freelancer label', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'freelancer', 'is_eu' => false,
        'bureaucracy_path' => null, 'profile_attributes' => [],
    ]);
    $task = Task::factory()->make(['applies_if' => null, 'situation' => ['freelancer'], 'eu_filter' => 'all']);
    $paths = app(PathGenerator::class);

    expect($paths->applicability($task, $paths->profileFor($user)))->toBe(Applicability::Unknown);
});

test('legacy task EU restrictions use the confirmed citizenship rather than a stale profile boolean', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'student', 'is_eu' => false, 'profile_attributes' => [],
    ]);
    app(CaseFactStore::class)->bootstrapConfirmedFacts($user, ['citizenship_group' => 'eu'], 'manual');
    $task = Task::factory()->make(['applies_if' => null, 'situation' => ['student'], 'eu_filter' => 'non_eu_only']);
    $paths = app(PathGenerator::class);

    expect($paths->applicability($task, $paths->profileFor($user)))->toBe(Applicability::No);
});

test('an unresolved conflict cannot select either disputed title for guidance', function () {
    $user = User::factory()->onboarded()->create(['profile_attributes' => []]);
    $store = app(CaseFactStore::class);
    $case = $store->bootstrapConfirmedFacts($user, ['current_residence_title' => 'blue_card'], 'manual');
    $candidate = $store->recordCandidate($case, 'current_residence_title', 'settlement_permit_9', 'manual');
    expect($store->confirmCandidate($candidate))->not->toBeNull();

    expect(app(CaseAttributes::class)->for($case)['current_residence_title'])->toBeNull()
        ->and(app(PathGenerator::class)->profileFor($user)->attributes['current_residence_title'])->toBeNull();
});

test('retiring an answer invalidates facts loaded before the change', function () {
    $user = User::factory()->onboarded()->create(['profile_attributes' => []]);
    $store = app(CaseFactStore::class);
    $case = $store->bootstrapConfirmedFacts($user, ['current_residence_title' => 'blue_card'], 'onboarding');
    $case->load('user', 'facts');
    $store->synchronizeConfirmedFacts($user, [], 'onboarding', ['current_residence_title']);

    expect(app(CaseAttributes::class)->for($case)['current_residence_title'])->toBeNull()
        ->and(app(PathGenerator::class)->profileFor($user)->attributes['current_residence_title'])->toBeNull();
});

test('historical bootstrap guesses are quarantined unless corroborated by explicit profile answers', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'student', 'is_eu' => null, 'bureaucracy_path' => 'non_eu_employee_blue_card',
        'profile_attributes' => ['entry_mode' => 'd_visa'],
    ]);
    $case = app(CaseFactStore::class)->bootstrapConfirmedFacts($user, [
        'citizenship_group' => 'non_eu', 'permit_track' => 'blue_card', 'entry_mode' => 'd_visa',
    ], 'legacy_profile');

    expect(app(CaseAttributes::class)->for($case))->toMatchArray([
        'citizenship_group' => null, 'permit_track' => null, 'entry_mode' => 'd_visa',
    ]);
    expect($case->facts()->count())->toBe(3); // Preserve history for reconfirmation/migration.
});

test('invalid historical stored facts are withheld on read without deleting their history', function () {
    $user = User::factory()->onboarded()->create(['profile_attributes' => ['visa_expires_at' => '2026-02-30']]);
    $case = BureaucracyCase::factory()->for($user)->create();
    BureaucracyCaseFact::factory()->for($case, 'case')->create([
        'key' => 'family_residence_permit_held_since', 'value' => '2099-01-01',
        'state' => 'confirmed', 'source' => 'structured_interview',
        'confirmed_at' => now(), 'reconfirm_at' => now()->addYear(), 'superseded_at' => null,
    ]);
    expect(app(CaseAttributes::class)->for($case))->toMatchArray([
        'visa_expires_at' => null, 'family_residence_permit_held_since' => null,
    ]);
    expect($case->facts()->count())->toBe(1);
});
