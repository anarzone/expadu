<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Models\BureaucracyCaseFact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserPlace;
use App\Models\UserTask;
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

test('ambiguous situations allow citizenship to be skipped without inferring non-EU status', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'veedel' => 'Sülz',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
    ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('bureaucracy'));
    expect($user->fresh()->bureaucracyCase->facts()->where('key', 'citizenship_group')->count())->toBe(0);
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

test('entry mode is offered but never required', function () {
    // It used to block the form. It is the highest-value optional answer — 17
    // applies_if references — but PendingAnswers asks for it on the Bureaucracy
    // page for every situation, so a skip is deferred rather than lost.
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'family_reunification',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->profile_attributes['entry_mode'] ?? null)->toBeNull();
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

test('onboarding records occupancy separately from proof and does not infer registration completion', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);
    $this->post(route('onboarding.complete'), ['situation' => 'eu_employee', 'veedel' => 'Nippes', 'arrival_date' => '2026-01-15', 'arrival_planned' => false, 'address_registration_status' => 'registrable', 'moved_in_at' => '2026-01-20', 'interests' => []])->assertRedirect(route('bureaucracy'));
    $user->refresh();
    $facts = app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString());
    expect($facts['values']['moved_in_at'])->toBe('2026-01-20')
        ->and($facts['values'])->not->toHaveKeys(['registration_status', 'housing_provider_confirmation']);
});

test('unknown registration paperwork does not invent occupancy or a housing classification', function (string $status) {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);
    $this->post(route('onboarding.complete'), ['situation' => 'eu_employee', 'veedel' => 'Nippes', 'arrival_date' => '2026-01-15', 'arrival_planned' => false, 'address_registration_status' => $status, 'interests' => []])->assertRedirect(route('bureaucracy'));
    $user->refresh();
    $facts = app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString());
    expect($facts['values'])->not->toHaveKeys(['moved_in_at', 'registration_status', 'housing_provider_confirmation'])
        ->and($user->profile_attributes['housing_status'] ?? null)->toBeNull();
})->with(['unsure' => 'unsure', 'unavailable' => 'not_registrable']);

test('a skipped address-registration answer is left unanswered, never assumed', function () {
    // No longer blocks the form: someone who has not moved in yet cannot
    // answer it. What must not happen is a guess — the Anmeldung card says its
    // 14-day clock has no start date instead (see SkippableAnswersTest).
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
        'arrival_planned' => false,
        'interests' => [],
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->onboarded_at)->not->toBeNull()
        ->and($user->profile_attributes['housing_status'] ?? null)->toBeNull()
        ->and($user->profile_attributes['moved_in_at'] ?? null)->toBeNull();
});

test('onboarding preserves a real move-in even when registration paperwork is unavailable', function () {
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
    ])->assertSessionHasNoErrors()->assertRedirect(route('bureaucracy'));
    expect($user->fresh()->bureaucracyCase->facts()->where('key', 'moved_in_at')->sole()->value)->toBe('2026-01-20');
});

test('a goal matching the current title remains a goal rather than a contradictory or completed application', function (array $answers) {
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
    ])->assertSessionHasNoErrors()->assertRedirect(route('bureaucracy'));
    $facts = app(ConfirmedFactView::class)->forCase($user->fresh()->bureaucracyCase, now()->toDateString());
    expect($facts['values'])->toMatchArray(['current_residence_title' => $answers['current_residence_title'], 'case_goal' => $answers['case_goal']])
        ->and($user->fresh()->bureaucracy_path)->toBeNull();
})->with([
    'Blue Card holder may need further Blue Card work' => [[
        'situation' => 'non_eu_employee',
        'current_residence_title' => 'blue_card',
        'case_goal' => 'blue_card',
    ]],
    'family permit holder may need further family-permit work' => [[
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

test('onboarding can be skipped without manufacturing answers or completion', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), []);

    $response->assertSessionHasNoErrors()->assertRedirect(route('bureaucracy'));
    expect($user->fresh()->bureaucracyCase->facts()->count())->toBe(0)
        ->and($user->fresh()->bureaucracy_path)->toBeNull()
        ->and(app(AccountHolderPlan::class)->for($user->fresh())['coverage']['state'])->toBe('outside_coverage');
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

test('entry mode is an encrypted attributed answer and is not duplicated in the public discovery profile', function () {
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
    $fact = $user->bureaucracyCase->facts()->where('key', 'entry_mode')->sole();
    expect($fact->value)->toBe('d_visa')->and($fact->source)->toBe('onboarding')->and($fact->recorded_by)->toBe($user->id)
        ->and($fact->getRawOriginal('encrypted_value'))->not->toContain('d_visa')
        ->and($user->profile_attributes['entry_mode'] ?? null)->toBeNull()
        ->and($user->profile_attributes['housing_status'] ?? null)->toBeNull();
});

test('redo onboarding retains legacy rows without transferring their progress into the new plan', function () {
    $task = Task::factory()->create([
        'key' => 'rd.anmeldung',
        'situation' => ['eu_employee'],
        'applies_if' => [['purpose' => 'employment', 'citizenship_group' => 'eu']],
        'documents_required' => ['Passport'],
    ]);

    $user = User::factory()->onboarded()->create(['situation' => 'eu_employee']);
    $this->actingAs($user);
    $userTask = UserTask::factory()->for($user)->create(['task_id' => $task->id, 'documents_checked' => ['Passport']]);

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

    expect($userTask->fresh()->documents_checked)->toBe(['Passport'])
        ->and(app(AccountHolderPlan::class)->for($user->fresh())['progress']['completed']['count'])->toBe(0);
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
        'purpose' => 'family',
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2026-09-30',
        'current_residence_title' => 'national_d_visa',
        'residence_title_expires_at' => '2026-10-15',
        'case_goal' => 'family_reunification_permit',
        'sponsor_current_title' => 'blue_card',
    ]);
    expect($facts)->not->toHaveKeys(['citizenship_group', 'permit_track', 'german_level']);
});

test('Blue Card onboarding keeps the requested goal separate from the current title and language preference', function () {
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
    expect($store->confirmedFact($case, 'permit_track'))->toBeNull();
    expect($store->confirmedFact($case, 'current_residence_title')->value)->toBe('standard_work_permit');
    expect($store->confirmedFact($case, 'case_goal')->value)->toBe('blue_card');
    expect($store->confirmedFact($case, 'german_level')->value)->toBe('b1');
    expect($store->confirmedFact($case, 'visa_expires_at'))->toBeNull();
});

test('re-onboarding requires an explicit change review instead of silently overwriting residence history', function () {
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

    $case = $user->fresh()->bureaucracyCase;
    $revision = $case->fact_version;
    $this->postJson(route('onboarding.complete'), [
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
    ])->assertUnprocessable()->assertJsonValidationErrors('situation');

    $store = app(CaseFactStore::class);
    expect($store->confirmedFact($case, 'visa_expires_at')->value)->toBe('2026-09-30')
        ->and($store->confirmedFact($case, 'sponsor_current_title')->value)->toBe('blue_card')
        ->and($store->confirmedFact($case, 'current_residence_title')->value)->toBe('national_d_visa')
        ->and($store->confirmedFact($case, 'case_goal')->value)->toBe('family_reunification_permit')
        ->and($store->confirmedFact($case, 'entry_mode')->value)->toBe('d_visa')
        ->and($case->fresh()->fact_version)->toBe($revision)
        ->and($user->fresh()->situation->value)->toBe('family_reunification');
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
