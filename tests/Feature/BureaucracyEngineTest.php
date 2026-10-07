<?php

use Carbon\CarbonImmutable;
use App\Profile\ProfileEngine;
use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Assessment\AssessmentInput;
use App\Models\Task;
use App\Models\User;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Profile\Applicability;
use Carbon\Carbon;

/** Isolates rendering/timing mechanics without approving real catalogue prose. */
function engineFixture(string $key, array $attributes = []): Task
{
    return Task::factory()->approvedFixture()->create([
        'key' => $key, 'title' => 'Synthetic '.$key, 'description' => 'Synthetic test content, not guidance.',
        'applies_if' => [[]], 'deadline_type' => 'none', 'depends_on' => [],
        ...$attributes,
    ]);
}

// ── Applicability evaluator (pure) ─────────────────────────────────────

dataset('applicability', [
    'empty applies_if matches everyone' => [null, ['purpose' => 'study'], Applicability::Yes],
    'scalar equality matches' => [[['purpose' => 'study']], ['purpose' => 'study'], Applicability::Yes],
    'scalar equality fails' => [[['purpose' => 'study']], ['purpose' => 'employment'], Applicability::No],
    'list membership matches' => [[['purpose' => ['digital_nomad', 'other']]], ['purpose' => 'other'], Applicability::Yes],
    'list membership fails' => [[['purpose' => ['digital_nomad', 'other']]], ['purpose' => 'study'], Applicability::No],
    'gte matches its boundary' => [[['blue_card_qualifying_months' => ['gte' => 20]]], ['blue_card_qualifying_months' => 20], Applicability::Yes],
    'gte rejects a lower value' => [[['blue_card_qualifying_months' => ['gte' => 20]]], ['blue_card_qualifying_months' => 19], Applicability::No],
    'lte matches its boundary' => [[['blue_card_qualifying_months' => ['lte' => 27]]], ['blue_card_qualifying_months' => 27], Applicability::Yes],
    'in operator matches strictly' => [[['german_level' => ['in' => ['b1', 'b2', 'c1', 'c2']]]], ['german_level' => 'b1'], Applicability::Yes],
    'present true matches non-null' => [[['residence_title_expires_at' => ['present' => true]]], ['residence_title_expires_at' => '2027-01-01'], Applicability::Yes],
    'present true rejects null' => [[['residence_title_expires_at' => ['present' => true]]], ['residence_title_expires_at' => null], Applicability::No],
    'present false matches null' => [[['residence_title_expires_at' => ['present' => false]]], ['residence_title_expires_at' => null], Applicability::Yes],
    'missing value stays unknown for comparisons' => [[['blue_card_qualifying_months' => ['gte' => 20]]], [], Applicability::Unknown],
    'numeric comparison rejects a non-numeric actual value' => [[['blue_card_qualifying_months' => ['gte' => 20]]], ['blue_card_qualifying_months' => 'twenty'], Applicability::No],
    'date age matches an exact month boundary' => [[['family_residence_permit_held_since' => ['at_least_months_ago' => 36]]], ['family_residence_permit_held_since' => '2023-08-03'], Applicability::Yes],
    'date age rejects one day before the month boundary' => [[['family_residence_permit_held_since' => ['at_least_months_ago' => 36]]], ['family_residence_permit_held_since' => '2023-08-04'], Applicability::No],
    'date age range matches a four year old permit' => [[['family_residence_permit_held_since' => ['months_ago_between' => [36, 59]]]], ['family_residence_permit_held_since' => '2022-09-01'], Applicability::Yes],
    'date age range excludes a permit at the five year boundary' => [[['family_residence_permit_held_since' => ['months_ago_between' => [36, 59]]]], ['family_residence_permit_held_since' => '2021-08-03'], Applicability::No],
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
    $this->travelTo('2026-08-03 10:00:00');

    expect(Applicability::evaluate($appliesIf, $attributes))->toBe($expected);
})->with('applicability');

test('unknown operator conditions expose the registered fact key', function () {
    $conditions = [['blue_card_qualifying_months' => ['gte' => 20]]];

    expect(Applicability::unknownAttributes($conditions, []))
        ->toBe(['blue_card_qualifying_months']);
});

test('malformed operator conditions fail explicitly', function (mixed $condition) {
    Applicability::evaluate([['blue_card_qualifying_months' => $condition]], [
        'blue_card_qualifying_months' => 22,
    ]);
})->with([
    'explicit null' => null,
    'unsupported operator' => [['gt' => 20]],
    'multiple operators' => [['gte' => 20, 'lte' => 27]],
    'non-numeric comparison operand' => [['gte' => 'twenty']],
    'non-list in operand' => [['in' => 'b1']],
    'non-boolean present operand' => [['present' => 1]],
    'invalid date age operand' => [['at_least_months_ago' => -1]],
    'invalid date age range' => [['months_ago_between' => [60, 36]]],
])->throws(DomainException::class);

test('every persona can reach approved address registration and is offered no unreviewed route', function (array $persona) {
    // Real catalogue, compiled the way a release is. Registration may wait on an
    // answer (needs_information) but must never be ruled out for someone who has
    // arrived, and guidance may only come from reviewed, approved content.
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $catalogue = app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all());
    $attributes = app(ProfileEngine::class)->build(BureaucracyPersonas::userFor($persona))->attributes;
    $values = array_filter([
        'arrival_planned' => false,
        'citizenship_group' => $persona['is_eu'] ? 'eu' : 'non_eu',
        'purpose' => $attributes['purpose'] ?? null,
        'entry_mode' => $persona['entry_mode'],
    ], fn ($value) => $value !== null);

    $assessment = (new AssessPerson)->assess(new AssessmentInput(['values' => $values], [], [], $catalogue, 'de-nrw-cologne', CarbonImmutable::now()))->toArray();
    $registration = collect($assessment['processes'])->firstWhere('definition_id', 'address.registration');

    expect($registration)->not->toBeNull()
        ->and(array_column($registration['variants'], 'assessment'))->each->toBeIn(['needs_information', 'supported_preparation', 'requirements_met'])
        ->and(collect($assessment['processes'])->pluck('variants')->flatten(1)->pluck('id')->filter(
            fn ($id) => Task::where('key', $id)->value('review_status') !== 'approved'
        )->all())->toBe([]);
})->with(fn () => array_map(fn ($persona) => [$persona], BureaucracyPersonas::coverage()));

// ── Teasers ────────────────────────────────────────────────────────────

test('an unanswered licence question renders as a teaser, not a task', function () {
    engineFixture('fixture.licence', ['applies_if' => [['license_country' => ['other']]]]);

    $user = User::factory()->onboarded()->create(['situation' => 'eu_employee']);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) {
        $props = $page->toArray()['props'];

        $keys = collect($props['tasks'])->flatten(1)->pluck('key');
        expect($keys)->not->toContain('fixture.licence');

        $teaser = collect($props['teasers'])->firstWhere('attribute', 'license_country');
        expect($teaser)->not->toBeNull();
        expect($teaser['options'])->toHaveCount(3);

        return true;
    });
});

test('answering a teaser recomputes the path and logs the change', function () {
    engineFixture('fixture.licence', ['applies_if' => [['license_country' => ['other']]]]);

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
        expect($keys)->toContain('fixture.licence');
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

test('fact-date deadlines use the exact registered fact date and pause when it is missing', function () {
    $user = User::factory()->onboarded()->create();
    $renewal = Task::factory()->create([
        'deadline_type' => 'fact_date',
        'deadline_days' => null,
        'deadline_fact_key' => 'residence_title_expires_at',
    ]);

    expect($renewal->computeDeadlineFor($user, [
        'residence_title_expires_at' => '2027-03-18',
    ])?->toDateString())->toBe('2027-03-18')
        ->and($renewal->computeDeadlineFor($user, []))->toBeNull()
        ->and($renewal->computeDeadlineFor($user, [
            'residence_title_expires_at' => '2027-02-31',
        ]))->toBeNull();
});

test('D-visa application deadlines still use visa expiry exactly', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => '2026-08-01']);
    $application = Task::factory()->create([
        'deadline_type' => 'permit_window',
        'deadline_days' => 90,
    ]);

    expect($application->computeDeadlineFor($user, [
        'entry_mode' => 'd_visa',
        'visa_expires_at' => '2026-12-14',
    ])?->toDateString())->toBe('2026-12-14')
        ->and($application->computeDeadlineFor($user, [
            'entry_mode' => 'd_visa',
            'visa_expires_at' => null,
        ]))->toBeNull();
});

test('temporary housing without an occupancy date leaves timing unknown rather than paused', function () {
    engineFixture('fixture.move-in', ['deadline_type' => 'days_since_move_in', 'deadline_days' => 14]);

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
            ->firstWhere('key', 'fixture.move-in');

        expect($card['deadline_tier'])->toBe('needs_answer');
        expect($card['deadline'])->toBeNull();
        expect($card['deadline_note'])->toContain('unknown');
        expect($card['bucket'])->toBe('active'); // still the primary next thing

        return true;
    });
});

test('a recorded move-in anchors the configured interval independently of arrival', function () {
    engineFixture('fixture.move-in', ['deadline_type' => 'days_since_move_in', 'deadline_days' => 14]);

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
            ->firstWhere('key', 'fixture.move-in');

        expect($card['deadline'])->toBe(now()->addDays(14)->toDateString());
        expect($card['deadline_tier'])->toBe('approaching');

        return true;
    });
});

test('a D-visa holder sees the visa-expiry framing instead of a 90-day date', function () {
    engineFixture('fixture.submit', ['deadline_type' => 'permit_window', 'deadline_days' => 90]);
    engineFixture('fixture.attend', ['depends_on' => ['fixture.submit']]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee',
        'profile_attributes' => ['entry_mode' => 'd_visa'],
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        // The submit task carries the permit window…
        expect($cards['fixture.submit']['deadline'])->toBeNull();
        expect($cards['fixture.submit']['deadline_note'])->toContain('visa expiry');
        expect($cards['fixture.submit']['deadline_tier'])->toBe('needs_answer');
        // …the attend task is gated on it, no clock of its own.
        expect($cards['fixture.attend']['blocked'])->toBeTrue();
        expect($cards['fixture.attend']['deadline'])->toBeNull();

        return true;
    });
});

// ── Figures + why-line ─────────────────────────────────────────────────

test('imported content carries substituted figures and cards explain themselves', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $blueCard = Task::where('key', 'bc.submit_application')->first();
    expect($blueCard->description)->toContain('€50,700');
    expect($blueCard->description)->not->toContain('{{figure:');
    expect($blueCard->review_status)->toBe('legacy');
    engineFixture('fixture.explanation', ['applies_if' => [['citizenship_group' => 'non_eu', 'purpose' => 'employment']]]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'is_eu' => false,
        'profile_attributes' => ['entry_mode' => 'd_visa'],
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'fixture.explanation');

        expect($card['why'])->toContain('non-EU');
        expect($card['why'])->toContain('employee');

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
    foreach (['fixture.child-care', 'fixture.birth-support', 'fixture.child-status', 'fixture.child-benefit'] as $key) {
        engineFixture($key, ['trigger_event' => 'child_born', 'deadline_type' => 'days_since_event', 'deadline_days' => 90]);
    }

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee',
    ]);

    $this->actingAs($user);

    // Dormant: no kita/elterngeld anywhere, and no teaser either.
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $props = $page->toArray()['props'];
        $keys = collect($props['tasks'])->flatten(1)->pluck('key');

        expect($keys)->not->toContain('fixture.child-care');
        expect($keys)->not->toContain('fixture.birth-support');
        expect(collect($props['teasers'])->pluck('attribute'))->not->toContain('child_born_at');

        return true;
    });

    // Synthetic interval: recording an event starts only its configured clock.
    $birth = now()->subDays(40)->toDateString();
    $this->post(route('profile.attributes'), [
        'attribute' => 'child_born_at',
        'value' => $birth,
        'source' => 'life_event',
    ])->assertRedirect();

    $this->get(route('bureaucracy'))->assertInertia(function ($page) use ($birth) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        expect($cards)->toHaveKey('fixture.child-care');
        expect($cards)->toHaveKey('fixture.birth-support');
        expect($cards)->toHaveKey('fixture.child-status');
        expect($cards)->toHaveKey('fixture.child-benefit');

        // Not a statutory benefit deadline: this is the fixture's interval.
        $expected = Carbon::parse($birth)->addDays(90)->toDateString();
        expect($cards['fixture.birth-support']['deadline'])->toBe($expected);

        return true;
    });
});

test('event-specific audience conditions avoid duplicate family and general variants', function () {
    engineFixture('fixture.child-care', ['trigger_event' => 'child_born']);
    engineFixture('fixture.birth-support', ['trigger_event' => 'child_born']);
    engineFixture('fixture.general-benefit', ['trigger_event' => 'child_born', 'applies_if' => [['purpose' => 'employment']]]);
    engineFixture('fixture.family-benefit', ['trigger_event' => 'child_born', 'applies_if' => [['purpose' => 'family']]]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification',
        'bureaucracy_path' => 'family_reunification',
        'profile_attributes' => ['child_born_at' => now()->subDays(10)->toDateString()],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');

        expect($keys)->toContain('fixture.child-care');
        expect($keys)->toContain('fixture.birth-support');
        expect($keys)->not->toContain('fixture.general-benefit');
        expect($keys)->toContain('fixture.family-benefit');

        return true;
    });
});

test('a graduation event respects a reviewed non-EU student audience', function () {
    engineFixture('fixture.graduation', ['trigger_event' => 'graduated', 'applies_if' => [['purpose' => 'study', 'citizenship_group' => 'non_eu']]]);

    $nonEuStudent = User::factory()->onboarded()->create([
        'situation' => 'student',
        'is_eu' => false,
        'profile_attributes' => ['graduated_at' => now()->subDays(3)->toDateString()],
    ]);

    $this->actingAs($nonEuStudent);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $keys = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('key');
        expect($keys)->toContain('fixture.graduation');

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
        expect($keys)->not->toContain('fixture.graduation');

        return true;
    });
});

// ── Office resolution + document cross-links ───────────────────────────

test('task cards resolve their office (Bezirk Bürgeramt) and document origins', function () {
    engineFixture('fixture.registration', ['booking_service_key' => 'anmeldung']);
    engineFixture('fixture.permit', ['booking_service_key' => 'auslaenderbehoerde']);
    engineFixture('fixture.tax-id', ['title' => 'Synthetic Steuer-ID source']);
    engineFixture('fixture.account', ['documents_required' => [['label' => 'Synthetic Tax ID copy', 'from' => 'fixture.tax-id']]]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
        'profile_attributes' => ['entry_mode' => 'visa_free'],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        // Anmeldung (a Bürgeramt service) pins no office — the concrete
        // Kundenzentrum is chosen at the end of the city's booking flow.
        expect($cards['fixture.registration']['office'])->toBeNull();
        // The permit is a single-site service, so its one office is pinned.
        expect($cards['fixture.permit']['office']['name'])->toBe('Ausländerbehörde Köln');

        // The bank task's Tax ID document points at the task that produces it.
        $taxDoc = collect($cards['fixture.account']['documents_required'])
            ->first(fn ($d) => is_array($d) && str_contains($d['label'], 'Tax ID'));
        expect($taxDoc['from_title'])->toContain('Steuer-ID');

        return true;
    });
});

// ── Book/attend split ──────────────────────────────────────────────────

test('the submit task is actionable on day one; attend waits for it', function () {
    engineFixture('fixture.submit', ['title' => 'Synthetic submission', 'deadline_type' => 'days_since_arrival', 'deadline_days' => 90]);
    engineFixture('fixture.attend', ['depends_on' => ['fixture.submit']]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee',
        'profile_attributes' => ['entry_mode' => 'visa_free'],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $cards = collect($page->toArray()['props']['tasks'])->flatten(1)->keyBy('key');

        expect($cards['fixture.submit']['blocked_by'])->toBe([]);
        expect($cards['fixture.submit']['blocked'])->toBeFalse();
        // Only the synthetic submission has a clock, not its dependent step.
        expect($cards['fixture.submit']['deadline'])->not->toBeNull();
        expect($cards['fixture.attend']['deadline'])->toBeNull();
        // Attend is gated on submit.
        expect($cards['fixture.attend']['blocked_by'])->toContain('Synthetic submission');

        return true;
    });
});

// ── Appointment tracking ───────────────────────────────────────────────

test('a booked appointment remains separate and cannot replace the configured deadline', function () {
    engineFixture('fixture.appointment', ['deadline_type' => 'days_since_arrival', 'deadline_days' => 90]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee',
        'profile_attributes' => ['entry_mode' => 'visa_free'],
    ]);
    $this->actingAs($user);
    $this->get(route('bureaucracy')); // materialise

    $userTask = $user->userTasks()
        ->whereHas('task', fn ($q) => $q->where('key', 'fixture.appointment'))
        ->first();
    $deadline = $userTask->absolute_deadline->toDateString();

    $appointment = now()->addDays(2)->setTime(9, 40);
    $this->patch(route('user-tasks.update', $userTask), [
        'appointment_at' => $appointment->toDateTimeString(),
    ])->assertRedirect();

    expect($userTask->fresh()->absolute_deadline->toDateString())->toBe($deadline);

    $this->get(route('bureaucracy'))->assertInertia(function ($page) use ($appointment, $deadline) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'fixture.appointment');

        expect($card['deadline'])->toBe($deadline);
        expect($card['deadline_note'])->toBeNull();
        expect(Carbon::parse($card['appointment_at'])->toDateTimeString())->toBe($appointment->toDateTimeString());

        return true;
    });
});

// ── Permanent-residency eligibility hint ───────────────────────────────

test('permit age alone never produces a permanent residence eligibility claim', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    // Standard employee, 4 years in → past the 36-month skilled-worker mark.
    $eligible = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'profile_attributes' => ['permit_held_since' => now()->subYears(4)->toDateString()],
    ]);

    $this->actingAs($eligible);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $hint = $page->toArray()['props']['eligibility'];

        expect($hint)->toBeNull();

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

test('a Blue Card path label and elapsed months do not establish qualifying service or eligibility', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'profile_attributes' => ['permit_held_since' => now()->subMonths(22)->toDateString()],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $hint = $page->toArray()['props']['eligibility'];

        expect($hint)->toBeNull();

        return true;
    });
});

// ── Journey reset (testing tool) ───────────────────────────────────────

test('user:reset-journey cannot pretend a dossier is reset by clearing only the old profile', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee_blue_card',
        'profile_attributes' => ['license_country' => 'other', 'child_born_at' => '2026-05-01'],
    ]);
    $this->actingAs($user);
    $this->get(route('bureaucracy')); // materialise tasks + progress
    expect($user->userTasks()->count())->toBeGreaterThan(0);
    $before = $user->fresh()->getRawOriginal();
    $tasks = $user->userTasks()->get()->toArray();
    $facts = $user->bureaucracyCase->facts()->get()->toArray();

    $this->artisan('user:reset-journey', ['email' => $user->email, '--force' => true])
        ->expectsOutputToContain('read-only persona preview')
        ->assertFailed();

    $user->refresh();
    expect($user->getRawOriginal())->toBe($before)
        ->and($user->userTasks()->get()->toArray())->toBe($tasks)
        ->and($user->bureaucracyCase->facts()->get()->toArray())->toBe($facts);
});

// ── Visa expiry anchoring ──────────────────────────────────────────────

test('a D-visa holder who gives the expiry date gets a real countdown', function () {
    engineFixture('fixture.visa', ['deadline_type' => 'permit_window', 'deadline_days' => 90]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee',
        'profile_attributes' => [
            'entry_mode' => 'd_visa',
            'visa_expires_at' => now()->addDays(30)->toDateString(),
        ],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'fixture.visa');

        expect($card['deadline'])->toBe(now()->addDays(30)->toDateString());
        expect($card['deadline_tier'])->toBe('approaching'); // 30 days on the visa scale
        expect($card['deadline_note'])->toContain('visa expiry');
        expect($card['deadline_action'])->toBeNull();

        return true;
    });
});

test('without the expiry date the card offers to capture it', function () {
    engineFixture('fixture.visa', ['deadline_type' => 'permit_window', 'deadline_days' => 90]);

    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'bureaucracy_path' => 'non_eu_employee',
        'profile_attributes' => ['entry_mode' => 'd_visa'],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'))->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks'])->flatten(1)
            ->firstWhere('key', 'fixture.visa');

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
