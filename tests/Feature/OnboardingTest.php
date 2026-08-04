<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Models\BureaucracyCaseFact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPlace;
use App\Onboarding\ApplyOnboardingAnswers;

test('onboarding page renders for non-onboarded user', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('onboarding'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('veedels')->missing('taskPreviews'));
});

test('onboarded user accessing onboarding is not redirected away', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('onboarding'));
    $response->assertOk();
});

test('onboarding can be completed with valid data', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
        'german_level' => 'a2',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'visa_free',
        'has_deutschlandticket' => true,
        'interests' => ['parks', 'museums', 'cafes'],
    ]);

    $response->assertRedirect(route('bureaucracy'));

    $user->refresh();
    expect($user->situation->value)->toBe('non_eu_employee');
    expect($user->veedel)->toBe('Ehrenfeld');
    expect($user->city)->toBe('Köln');
    expect($user->german_level->value)->toBe('a2');
    expect($user->has_deutschlandticket)->toBeTrue();
    expect($user->onboarded_at)->not->toBeNull();
});

test('ambiguous situations require the EU question', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'veedel' => 'Sülz',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
    ]);

    $response->assertSessionHasErrors('is_eu');
});

test('employee situations do not require the EU question', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'interests' => ['parks', 'museums', 'cafes'],
    ]);

    $response->assertRedirect(route('bureaucracy'));
});

test('family and non-EU onboarding flows require an entry mode', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'family_reunification',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertSessionHasErrors('entry_mode');
});

test('german level is optional', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'is_eu' => true,
        'veedel' => 'Deutz',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'interests' => ['parks', 'museums', 'cafes'],
    ]);

    $response->assertRedirect(route('bureaucracy'));
});

test('interests are optional', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'interests' => [],
    ]);

    $response->assertRedirect(route('bureaucracy'));
    expect($user->fresh()->interests)->toBe([]);
});

test('onboarding limits interests to the configured maximum', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'interests' => ['parks', 'museums', 'cafes', 'sports', 'swimming', 'sights', 'family'],
    ]);

    $response->assertSessionHasErrors('interests');
});

test('onboarding derives address deadlines only from a registrable move-in', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);
    $this->post(route('onboarding.complete'), ['situation' => 'eu_employee', 'veedel' => 'Nippes', 'arrival_date' => '2026-01-15', 'arrival_planned' => false, 'address_registration_status' => 'registrable', 'moved_in_at' => '2026-01-20', 'interests' => []])->assertRedirect(route('bureaucracy'));
    $user->refresh();
    expect($user->profile_attributes['housing_status'])->toBe('long_term')->and($user->profile_attributes['moved_in_at'])->toBe('2026-01-20');
});

test('onboarding pauses address deadlines when registration is unknown or unavailable', function (string $status) {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);
    $this->post(route('onboarding.complete'), ['situation' => 'eu_employee', 'veedel' => 'Nippes', 'arrival_date' => '2026-01-15', 'arrival_planned' => false, 'address_registration_status' => $status, 'interests' => []])->assertRedirect(route('bureaucracy'));
    $user->refresh();
    expect($user->profile_attributes['housing_status'] ?? null)->toBe($status === 'not_registrable' ? 'temporary' : null)->and($user->profile_attributes['moved_in_at'] ?? null)->toBeNull();
})->with(['unsure' => 'unsure', 'unavailable' => 'not_registrable']);

test('onboarding requires an explicit address-registration answer before it persists profile facts', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'interests' => [],
    ])->assertSessionHasErrors('address_registration_status');

    $user->refresh();
    expect($user->onboarded_at)->toBeNull()
        ->and($user->profile_attributes['housing_status'] ?? null)->toBeNull()
        ->and($user->profile_attributes['moved_in_at'] ?? null)->toBeNull();
});

test('onboarding rejects a move-in date for a non-registrable address', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'moved_in_at' => '2026-01-20',
        'interests' => [],
    ])->assertSessionHasErrors('moved_in_at');
});

test('onboarding rejects a goal that contradicts the current residence title', function (array $answers) {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'has_permit',
        'interests' => [],
        ...$answers,
    ])->assertSessionHasErrors('case_goal');
})->with([
    'Blue Card holder cannot apply for another Blue Card' => [[
        'situation' => 'non_eu_employee',
        'current_residence_title' => 'blue_card',
        'case_goal' => 'blue_card',
    ]],
    'family permit holder cannot apply for family reunification again' => [[
        'situation' => 'family_reunification',
        'current_residence_title' => 'family_reunification',
        'case_goal' => 'family_reunification_permit',
    ]],
]);

test('onboarding fails with invalid situation', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'invalid_value',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertSessionHasErrors('situation');
});

test('onboarding fails with unknown veedel', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Atlantis',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertSessionHasErrors('veedel');
});

test('onboarding fails without required fields', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), []);

    $response->assertSessionHasErrors(['situation', 'veedel', 'arrival_planned', 'arrival_date', 'address_registration_status']);
});

test('onboarding fails with future arrival date', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2030-01-01',
        'arrival_planned' => false,
    ]);

    $response->assertSessionHasErrors('arrival_date');
});

test('non-onboarded user is redirected from protected pages', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('explore'));
    $response->assertRedirect(route('onboarding'));
});

test('onboarded user can access protected pages', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('explore'));
    $response->assertOk();
});

test('entry mode and derived address status land in the attribute bag with audit log', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'd_visa',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('bureaucracy'));

    $user->refresh();
    expect($user->profile_attributes['entry_mode'])->toBe('d_visa');
    expect($user->profile_attributes['housing_status'])->toBe('temporary');
    expect($user->attributeChanges()->where('source', 'onboarding')->count())->toBe(3);
});

test('redo onboarding re-asks everything but keeps all progress', function () {
    Task::factory()->create([
        'key' => 'rd.anmeldung',
        'situation' => ['eu_employee'],
        'applies_if' => [['purpose' => 'employment', 'citizenship_group' => 'eu']],
        'documents_required' => ['Passport'],
    ]);

    $user = User::factory()->onboarded()->create(['situation' => 'eu_employee']);
    $this->actingAs($user);
    $this->get(route('bureaucracy')); // materialise

    $userTask = $user->userTasks()->first();
    $userTask->update(['documents_checked' => ['Passport']]); // real progress

    $this->post(route('onboarding.restart'))->assertRedirect(route('onboarding'));

    $user->refresh();
    expect($user->onboarded_at)->toBeNull();
    // Answers stay (they're re-asked and overwritten) and progress is KEPT.
    expect($user->situation->value)->toBe('eu_employee');
    expect($user->userTasks()->count())->toBe(1);
    expect($userTask->fresh()->documents_checked)->toBe(['Passport']);

    // Protected pages bounce back to onboarding until it's completed again.
    $this->get(route('explore'))->assertRedirect(route('onboarding'));

    $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'is_eu' => true,
        'veedel' => 'Nippes',
        'arrival_date' => now()->subDays(3)->toDateString(),
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('bureaucracy'));

    // The touched old-path task survives in the records lane.
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $ghosts = collect($page->toArray()['props']['tasks']['no_longer_relevant'])->pluck('key');
        expect($ghosts)->toContain('rd.anmeldung');

        return true;
    });
});

test('planning mode completes onboarding with no arrival date', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
        'arrival_planned' => true, // "Still planning" — no arrival date
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'd_visa',
        'interests' => ['parks', 'museums', 'cafes'],
    ]);

    $response->assertRedirect(route('bureaucracy'));

    $user->refresh();
    expect($user->onboarded_at)->not->toBeNull();
    expect($user->arrival_date)->toBeNull();
});

test('planning mode lands in the Before-you-fly phase with no firing deadlines', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
        'arrival_planned' => true,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'visa_free',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('bureaucracy'));

    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $props = $page->toArray()['props'];
        expect($props['phases']['current'])->toBe('before');

        // Without an arrival date, no card carries a concrete deadline.
        $cards = [...$props['tasks']['active'], ...$props['tasks']['upcoming']];
        foreach ($cards as $card) {
            expect($card['deadline'])->toBeNull();
        }

        return true;
    });
});

test('family D-visa onboarding confirms explicit canonical facts without inventing refinements', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'family_reunification',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2026-09-30',
        'current_residence_title' => 'national_d_visa',
        'residence_title_expires_at' => '2026-10-15',
        'case_goal' => 'family_reunification_permit',
        'sponsor_current_title' => 'blue_card',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('bureaucracy'));

    $case = $user->fresh()->bureaucracyCase;
    expect($case)->not->toBeNull();

    $facts = BureaucracyCaseFact::query()
        ->where('case_id', $case->id)
        ->where('state', 'confirmed')
        ->get()
        ->mapWithKeys(fn (BureaucracyCaseFact $fact): array => [$fact->key => $fact->value]);

    expect($facts->all())->toMatchArray([
        'citizenship_group' => 'non_eu',
        'purpose' => 'family',
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2026-09-30',
        'current_residence_title' => 'national_d_visa',
        'residence_title_expires_at' => '2026-10-15',
        'case_goal' => 'family_reunification_permit',
        'sponsor_current_title' => 'blue_card',
    ]);
    expect($facts)->not->toHaveKeys(['permit_track', 'german_level']);
});

test('Blue Card onboarding maps only the documented German level and derives the Blue Card track', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'has_permit',
        'current_residence_title' => 'standard_work_permit',
        'case_goal' => 'blue_card',
        'german_level' => 'a2',
        'documented_german_level' => 'b1',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('bureaucracy'));

    $case = $user->fresh()->bureaucracyCase;
    expect($case)->not->toBeNull();

    $store = app(CaseFactStore::class);
    expect($store->confirmedFact($case, 'permit_track')->value)->toBe('blue_card');
    expect($store->confirmedFact($case, 'german_level')->value)->toBe('b1');
    expect($store->confirmedFact($case, 'visa_expires_at'))->toBeNull();
});

test('re-onboarding retires visa sponsor and refinement facts that are no longer applicable', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'family_reunification',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2026-09-30',
        'current_residence_title' => 'national_d_visa',
        'case_goal' => 'family_reunification_permit',
        'sponsor_current_title' => 'blue_card',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('bureaucracy'));

    $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2027-01-31',
        'current_residence_title' => 'blue_card',
        'residence_title_expires_at' => '2027-02-28',
        'case_goal' => 'blue_card',
        'sponsor_current_title' => 'blue_card',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('bureaucracy'));

    $case = $user->fresh()->bureaucracyCase;
    $store = app(CaseFactStore::class);
    expect($store->confirmedFact($case, 'visa_expires_at'))->toBeNull();
    expect($store->confirmedFact($case, 'sponsor_current_title'))->toBeNull();
    expect($store->confirmedFact($case, 'current_residence_title'))->toBeNull();
    expect($store->confirmedFact($case, 'case_goal'))->toBeNull();
    expect($store->confirmedFact($case, 'entry_mode'))->toBeNull();
    expect($store->confirmedFact($case, 'residence_title_expires_at'))->toBeNull();
    expect($store->confirmedFact($case, 'permit_track'))->toBeNull();
    expect($user->fresh()->profile_attributes['entry_mode'] ?? null)->toBeNull();
    expect($user->fresh()->profile_attributes['visa_expires_at'] ?? null)->toBeNull();
});

test('onboarding rolls back profile and canonical fact writes when required place creation fails', function () {
    $user = User::factory()->notOnboarded()->create();
    $originalDispatcher = UserPlace::getEventDispatcher();
    UserPlace::setEventDispatcher(clone $originalDispatcher);

    try {
        UserPlace::creating(fn () => throw new RuntimeException('Place creation failed.'));

        expect(fn () => app(ApplyOnboardingAnswers::class)->execute($user, [
            'situation' => 'non_eu_employee',
            'veedel' => 'Ehrenfeld',
            'arrival_date' => '2026-01-15',
            'arrival_planned' => false,
            'address_registration_status' => 'not_registrable',
            'entry_mode' => 'visa_free',
            'interests' => ['parks', 'museums', 'cafes'],
        ]))->toThrow(RuntimeException::class);
    } finally {
        UserPlace::setEventDispatcher($originalDispatcher);
    }

    $user->refresh();
    expect(UserPlace::getEventDispatcher())->toBe($originalDispatcher);
    expect($user->onboarded_at)->toBeNull();
    expect($user->bureaucracyCase)->toBeNull();
    expect($user->places()->count())->toBe(0);
});
