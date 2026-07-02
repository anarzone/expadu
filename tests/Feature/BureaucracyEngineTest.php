<?php

use App\Models\Task;
use App\Models\User;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Profile\Applicability;
use Carbon\Carbon;

// ── Applicability evaluator (pure) ─────────────────────────────────────

dataset('applicability', [
    'empty applies_if matches everyone' => [null, ['purpose' => 'study'], Applicability::Yes],
    'scalar equality matches' => [[['purpose' => 'study']], ['purpose' => 'study'], Applicability::Yes],
    'scalar equality fails' => [[['purpose' => 'study']], ['purpose' => 'employment'], Applicability::No],
    'list membership matches' => [[['purpose' => ['digital_nomad', 'other']]], ['purpose' => 'other'], Applicability::Yes],
    'list membership fails' => [[['purpose' => ['digital_nomad', 'other']]], ['purpose' => 'study'], Applicability::No],
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
    expect(Applicability::evaluate($appliesIf, $attributes))->toBe($expected);
})->with('applicability');

// ── Table-driven path fixtures over the REAL catalogue ─────────────────
// One fixture per case: profile → keys that must be visible / absent.
// This is the regression net that keeps 24+ cases honest.

dataset('path fixtures', [
    'non-EU employee, standard, visa-free' => [
        ['situation' => 'non_eu_employee'],
        [],
        ['nee.anmeldung', 'nee.residence_permit', 'shared.long_game'],
        ['bc.blue_card', 'eue.anmeldung', 'core.anmeldung', 'stu.residence_permit'],
    ],
    'non-EU employee who already holds a permit' => [
        ['situation' => 'non_eu_employee'],
        ['entry_mode' => 'has_permit'],
        ['nee.anmeldung'],
        ['nee.residence_permit'],
    ],
    'Blue Card path shares the employee spine' => [
        ['situation' => 'non_eu_employee', 'bureaucracy_path' => 'non_eu_employee_blue_card'],
        [],
        ['nee.anmeldung', 'bc.blue_card', 'bc.ne_fast_track'],
        ['nee.residence_permit', 'nee.ne_check'],
    ],
    'EU employee: shortest path, no permits, no non-EU cards' => [
        ['situation' => 'eu_employee'],
        [],
        ['eue.anmeldung', 'shared.church_tax'],
        ['nee.residence_permit', 'bc.blue_card', 'shared.long_game', 'shared.fiktionsbescheinigung'],
    ],
    'EU student skips the permit' => [
        ['situation' => 'student', 'is_eu' => true],
        [],
        ['stu.anmeldung', 'stu.enrolment'],
        ['stu.residence_permit', 'stu.work_rules'],
    ],
    'non-EU student gets the §16b permit' => [
        ['situation' => 'student', 'is_eu' => false],
        [],
        ['stu.residence_permit', 'stu.work_rules'],
        [],
    ],
    'Gewerbe freelancer swaps the permit, keeps the tax spine' => [
        ['situation' => 'freelancer', 'is_eu' => false, 'bureaucracy_path' => 'freelancer_gewerbe'],
        [],
        ['fre.anmeldung', 'fre.fragebogen', 'gw.residence_permit', 'gw.gewerbeanmeldung'],
        ['fre.residence_permit', 'fre.gewerbe_check'],
    ],
    'family joining a German citizen' => [
        ['situation' => 'family_reunification', 'bureaucracy_path' => 'family_reunification_of_german'],
        [],
        ['fam.anmeldung', 'famde.residence_permit', 'famde.ne_three_years'],
        ['fam.residence_permits', 'fameu.aufenthaltskarte'],
    ],
    'digital nomad gets the core spine only' => [
        ['situation' => 'digital_nomad', 'is_eu' => false],
        [],
        ['core.anmeldung', 'shared.schufa'],
        ['nee.anmeldung', 'nee.residence_permit', 'stu.anmeldung'],
    ],
    'foreign licence answer reveals the driving task' => [
        ['situation' => 'eu_employee'],
        ['license_country' => 'other'],
        ['shared.driving_licence'],
        [],
    ],
    'EU licence answer keeps the driving task away' => [
        ['situation' => 'eu_employee'],
        ['license_country' => 'eu'],
        [],
        ['shared.driving_licence'],
    ],
]);

test('path fixtures: each profile computes the expected catalogue subset', function (array $userFields, array $attributes, array $visible, array $absent) {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        ...$userFields,
        'profile_attributes' => $attributes ?: null,
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) use ($visible, $absent) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');

        foreach ($visible as $key) {
            expect($keys)->toContain($key);
        }
        foreach ($absent as $key) {
            expect($keys)->not->toContain($key);
        }

        return true;
    });
})->with('path fixtures');

// ── Teasers ────────────────────────────────────────────────────────────

test('an unanswered licence question renders as a teaser, not a task', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create(['situation' => 'eu_employee']);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        $keys = collect($props['tasks'])->flatten(1)->pluck('key');
        expect($keys)->not->toContain('shared.driving_licence');

        $teaser = collect($props['teasers'])->firstWhere('attribute', 'license_country');
        expect($teaser)->not->toBeNull();
        expect($teaser['options'])->toHaveCount(3);

        return true;
    });
});

test('answering a teaser recomputes the path and logs the change', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create(['situation' => 'eu_employee']);

    $this->actingAs($user);
    $this->post(route('profile.attributes'), [
        'attribute' => 'license_country',
        'value' => 'other',
        'source' => 'teaser',
    ])->assertRedirect();

    expect($user->fresh()->profile_attributes['license_country'])->toBe('other');
    expect($user->attributeChanges()->where('attribute', 'license_country')->count())->toBe(1);

    $response = $this->get(route('bureaucracy'));
    $response->assertInertia(function ($page) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');
        expect($keys)->toContain('shared.driving_licence');
        expect(collect($page->toArray()['props']['teasers']))->toBeEmpty();

        return true;
    });
});

test('the attribute endpoint rejects unknown attributes and values', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user);
    $this->post(route('profile.attributes'), ['attribute' => 'is_admin', 'value' => true])
        ->assertSessionHasErrors('attribute');
    $this->post(route('profile.attributes'), ['attribute' => 'license_country', 'value' => 'mars'])
        ->assertSessionHasErrors('value');
});

// ── Deadline anchors ───────────────────────────────────────────────────

test('temporary housing pauses the Anmeldung deadline instead of going overdue', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    // Arrived 30 days ago — the naive 14-day clock would scream overdue.
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'arrival_date' => now()->subDays(30)->toDateString(),
        'profile_attributes' => ['housing_status' => 'temporary'],
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'eue.anmeldung');

        expect($card['deadline_tier'])->toBe('paused');
        expect($card['deadline'])->toBeNull();
        expect($card['deadline_note'])->toContain('paused');
        expect($card['bucket'])->toBe('active'); // still the primary next thing

        return true;
    });
});

test("'I've moved in' starts the real 14-day clock from the move-in date", function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'arrival_date' => now()->subDays(30)->toDateString(),
        'profile_attributes' => ['housing_status' => 'temporary'],
    ]);

    $this->actingAs($user);
    $this->post(route('profile.attributes'), [
        'attribute' => 'moved_in_at',
        'value' => now()->toDateString(),
        'source' => 'banner',
    ])->assertRedirect();

    $response = $this->get(route('bureaucracy'));
    $response->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'eue.anmeldung');

        expect($card['deadline'])->toBe(now()->addDays(14)->toDateString());
        expect($card['deadline_tier'])->toBe('approaching');

        return true;
    });
});

test('a D-visa holder sees the visa-expiry framing instead of a 90-day date', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['entry_mode' => 'd_visa'],
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        // The submit task carries the permit window…
        expect($cards['nee.submit_application']['deadline'])->toBeNull();
        expect($cards['nee.submit_application']['deadline_note'])->toContain('visa expires');
        expect($cards['nee.submit_application']['deadline_tier'])->toBe('urgent');
        // …the attend task is gated on it, no clock of its own.
        expect($cards['nee.residence_permit']['blocked'])->toBeTrue();
        expect($cards['nee.residence_permit']['deadline'])->toBeNull();

        return true;
    });
});

// ── Figures + why-line ─────────────────────────────────────────────────

test('imported content carries substituted figures and cards explain themselves', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $blueCard = Task::where('key', 'bc.submit_application')->first();
    expect($blueCard->description)->toContain('€50,700');
    expect($blueCard->description)->not->toContain('{{figure:');

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee_blue_card',
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'bc.blue_card');

        expect($card['why'])->toContain('non-EU');
        expect($card['why'])->toContain('Blue Card');

        return true;
    });
});

// ── Phases ─────────────────────────────────────────────────────────────

test('the roadmap phase follows days since arrival', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $fresh = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'arrival_date' => now()->subDays(5)->toDateString(),
    ]);

    $this->actingAs($fresh);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        expect($page->toArray()['props']['phases']['current'])->toBe('first_14');

        return true;
    });

    $settled = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'arrival_date' => now()->subDays(200)->toDateString(),
    ]);

    $this->actingAs($settled);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        expect($page->toArray()['props']['phases']['current'])->toBe('settled');

        return true;
    });
});

// ── Life events ────────────────────────────────────────────────────────

test('life-event tasks stay dormant until the event is recorded — then wake with anchored deadlines', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $this->actingAs($user);

    // Dormant: no kita/elterngeld anywhere, and no teaser either.
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $props = $page->toArray()['props'];
        $keys = collect($props['tasks'])->flatten(1)->pluck('key');

        expect($keys)->not->toContain('le.kita');
        expect($keys)->not->toContain('le.elterngeld');
        expect(collect($props['teasers'])->pluck('attribute'))->not->toContain('child_born_at');

        return true;
    });

    // Record the birth — 40 days ago, so the Elterngeld window is ticking.
    $birth = now()->subDays(40)->toDateString();
    $this->post(route('profile.attributes'), [
        'attribute' => 'child_born_at',
        'value' => $birth,
        'source' => 'life_event',
    ])->assertRedirect();

    $this->get(route('bureaucracy'))->assertInertia(function ($page) use ($birth) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        expect($cards)->toHaveKey('le.kita');
        expect($cards)->toHaveKey('le.elterngeld');
        expect($cards)->toHaveKey('le.child_permit'); // non-EU parent
        expect($cards)->toHaveKey('le.kindergeld');

        // Elterngeld deadline = birth + 90 days, anchored to the EVENT date.
        $expected = Carbon::parse($birth)->addDays(90)->toDateString();
        expect($cards['le.elterngeld']['deadline'])->toBe($expected);

        return true;
    });
});

test('the Kindergeld life-event task skips the family branch (it has its own)', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification',
        'profile_attributes' => ['child_born_at' => now()->subDays(10)->toDateString()],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');

        expect($keys)->toContain('le.kita');
        expect($keys)->toContain('le.elterngeld');
        expect($keys)->not->toContain('le.kindergeld'); // fam.kindergeld covers it
        expect($keys)->toContain('fam.kindergeld');

        return true;
    });
});

test('graduation wakes the 18-month job-search permit for non-EU students only', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $nonEuStudent = User::factory()->onboarded()->create([
        'situation' => 'student',
        'is_eu' => false,
        'profile_attributes' => ['graduated_at' => now()->subDays(3)->toDateString()],
    ]);

    $this->actingAs($nonEuStudent);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');
        expect($keys)->toContain('le.job_search_permit');

        return true;
    });

    $euStudent = User::factory()->onboarded()->create([
        'situation' => 'student',
        'is_eu' => true,
        'profile_attributes' => ['graduated_at' => now()->subDays(3)->toDateString()],
    ]);

    $this->actingAs($euStudent);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');
        expect($keys)->not->toContain('le.job_search_permit');

        return true;
    });
});

// ── Office resolution + document cross-links ───────────────────────────

test('task cards resolve their office (Bezirk Bürgeramt) and document origins', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        // Anmeldung (a Bürgeramt service) pins no office — the concrete
        // Kundenzentrum is chosen at the end of the city's booking flow.
        expect($cards['nee.anmeldung']['office'])->toBeNull();
        // The permit is a single-site service, so its one office is pinned.
        expect($cards['nee.residence_permit']['office']['name'])->toBe('Ausländerbehörde Köln');

        // The bank task's Tax ID document points at the task that produces it.
        $taxDoc = collect($cards['nee.bank_account']['documents_required'])
            ->first(fn ($d) => is_array($d) && str_contains($d['label'], 'Tax ID'));
        expect($taxDoc['from_title'])->toContain('Steuer-ID');

        return true;
    });
});

// ── Book/attend split ──────────────────────────────────────────────────

test('the submit task is actionable on day one; attend waits for it', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        // Submit: blocked only by Anmeldung — NOT by health insurance.
        expect($cards['nee.submit_application']['blocked_by'])
            ->not->toContain('Sign up for German health insurance');
        // Submit carries the 90-day window; attend has no clock of its own.
        expect($cards['nee.submit_application']['deadline'])->not->toBeNull();
        expect($cards['nee.residence_permit']['deadline'])->toBeNull();
        // Attend is gated on submit.
        expect($cards['nee.residence_permit']['blocked_by'])
            ->toContain('Submit your permit application online — today (§18a/b)');

        return true;
    });
});

// ── Appointment tracking ───────────────────────────────────────────────

test('a booked appointment becomes the effective deadline and feeds reminders', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);
    $this->actingAs($user);
    $this->get(route('bureaucracy')); // materialise

    $userTask = $user->userTasks()
        ->whereHas('task', fn ($q) => $q->where('key', 'nee.residence_permit'))
        ->first();

    $appointment = now()->addDays(2)->setTime(9, 40);
    $this->patch(route('user-tasks.update', $userTask), [
        'appointment_at' => $appointment->toDateTimeString(),
    ])->assertRedirect();

    // The push pipeline reads absolute_deadline — the appointment wins.
    expect($userTask->fresh()->absolute_deadline->toDateTimeString())
        ->toBe($appointment->toDateTimeString());

    $this->get(route('bureaucracy'))->assertInertia(function ($page) use ($appointment) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'nee.residence_permit');

        expect($card['deadline'])->toBe($appointment->toDateString());
        expect($card['deadline_tier'])->toBe('critical'); // 2 days out
        expect($card['deadline_note'])->toContain('Your appointment');
        expect($card['appointment_at'])->not->toBeNull();

        return true;
    });
});

// ── Permanent-residency eligibility hint ───────────────────────────────

test('the NE hint appears once the permit has been held past the track threshold', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    // Standard employee, 4 years in → past the 36-month skilled-worker mark.
    $eligible = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYears(4)->toDateString()],
    ]);

    $this->actingAs($eligible);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $hint = $page->toArray()['props']['eligibility'];

        expect($hint)->not->toBeNull();
        expect($hint['threshold_months'])->toBe(36);
        expect($hint['track_note'])->toContain('Skilled workers');

        return true;
    });

    // One year in → no hint; never recorded → no hint.
    $early = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYear()->toDateString()],
    ]);
    $this->actingAs($early);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        expect($page->toArray()['props']['eligibility'])->toBeNull();

        return true;
    });
});

test('Blue Card holders cross the NE mark at 21 months', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'profile_attributes' => ['permit_held_since' => now()->subMonths(22)->toDateString()],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $hint = $page->toArray()['props']['eligibility'];

        expect($hint)->not->toBeNull();
        expect($hint['threshold_months'])->toBe(21);

        return true;
    });
});

// ── Journey reset (testing tool) ───────────────────────────────────────

test('user:reset-journey sends a user back through onboarding for a clean replay', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'profile_attributes' => ['license_country' => 'other', 'child_born_at' => '2026-05-01'],
    ]);
    $this->actingAs($user);
    $this->get(route('bureaucracy')); // materialise tasks + progress
    expect($user->userTasks()->count())->toBeGreaterThan(0);

    $this->artisan('user:reset-journey', ['email' => $user->email, '--force' => true])
        ->assertSuccessful();

    $user->refresh();
    expect($user->onboarded_at)->toBeNull();
    expect($user->situation)->toBeNull();
    expect($user->bureaucracy_path)->toBeNull();
    expect($user->profile_attributes)->toBeNull();
    expect($user->userTasks()->count())->toBe(0);
    expect($user->attributeChanges()->count())->toBe(0);

    // The middleware sends them straight back to onboarding…
    $this->get(route('explore'))->assertRedirect(route('onboarding'));

    // …and a different persona replays cleanly on the same account.
    $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'is_eu' => true,
        'veedel' => 'Nippes',
        'arrival_date' => now()->subDays(3)->toDateString(),
        'housing_status' => 'long_term',
        'interests' => ['parks', 'museums', 'cafes'],
    ])->assertRedirect(route('dashboard'));

    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');
        expect($keys)->toContain('stu.anmeldung');
        expect($keys)->not->toContain('bc.blue_card');

        return true;
    });
});

// ── Visa expiry anchoring ──────────────────────────────────────────────

test('a D-visa holder who gives the expiry date gets a real countdown', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => [
            'entry_mode' => 'd_visa',
            'visa_expires_at' => now()->addDays(30)->toDateString(),
        ],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'nee.submit_application');

        expect($card['deadline'])->toBe(now()->addDays(30)->toDateString());
        expect($card['deadline_tier'])->toBe('approaching'); // 30 days on the visa scale
        expect($card['deadline_note'])->toContain('visa expiry');
        expect($card['deadline_action'])->toBeNull();

        return true;
    });
});

test('without the expiry date the card offers to capture it', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['entry_mode' => 'd_visa'],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'nee.submit_application');

        expect($card['deadline'])->toBeNull();
        expect($card['deadline_action'])->toBe('visa_expiry');

        return true;
    });

    // The strip posts a FUTURE date — the endpoint must accept it.
    $this->post(route('profile.attributes'), [
        'attribute' => 'visa_expires_at',
        'value' => now()->addMonths(2)->toDateString(),
        'source' => 'banner',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($user->fresh()->profile_attributes['visa_expires_at'])
        ->toBe(now()->addMonths(2)->toDateString());
});

// ── Push deep links ────────────────────────────────────────────────────

test('deadline notifications deep-link to the specific task', function () {
    $n = new BureaucracyDeadlineNotification(
        taskTitle: 'Anmeldung',
        tier: 'critical',
        daysRemaining: 2,
        deadline: now()->addDays(2)->toDateString(),
        taskId: 42,
    );

    expect($n->toArray(null)['url'])->toBe('/bureaucracy?focus=42');
});
