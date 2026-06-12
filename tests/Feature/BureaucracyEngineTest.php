<?php

use App\Models\Task;
use App\Models\User;
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
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'nee.residence_permit');

        expect($card['deadline'])->toBeNull();
        expect($card['deadline_note'])->toContain('visa expires');
        expect($card['deadline_tier'])->toBe('urgent');

        return true;
    });
});

// ── Figures + why-line ─────────────────────────────────────────────────

test('imported content carries substituted figures and cards explain themselves', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $blueCard = Task::where('key', 'bc.blue_card')->first();
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

        // Anmeldung routes to the user's Bezirk office…
        expect($cards['nee.anmeldung']['office']['name'])->toBe('Bürgeramt Ehrenfeld');
        // …the permit to the single Ausländerbehörde site.
        expect($cards['nee.residence_permit']['office']['name'])->toBe('Ausländerbehörde Köln');

        // The bank task's Tax ID document points at the task that produces it.
        $taxDoc = collect($cards['nee.bank_account']['documents_required'])
            ->first(fn ($d) => is_array($d) && str_contains($d['label'], 'Tax ID'));
        expect($taxDoc['from_title'])->toContain('Steuer-ID');

        return true;
    });
});
