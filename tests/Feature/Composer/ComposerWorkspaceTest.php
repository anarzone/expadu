<?php

use App\Composer\AppointmentRepository;
use App\Composer\Constraints;
use App\Composer\PrivatePlanCache;
use App\Composer\TodayPlanStore;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\Spot;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function workspacePlan(): array
{
    $day = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $offset = CarbonImmutable::parse($day, 'Europe/Berlin')->format('P');
    Spot::factory()->create(['id' => 1, 'name' => 'Park', 'category' => 'park', 'lat' => 50.94, 'lng' => 6.95, 'tags' => ['opening_hours' => 'Mo-Su 00:00-24:00']]);
    config(['events.geocoding.expected_polygon_count' => 1]);
    DB::table('veedels')->insert(['name' => 'Workspace Fixture Boundary', 'bezirk' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    DB::statement("UPDATE veedels SET boundary = ST_Multi(ST_GeomFromText('POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))', 4326)) WHERE name = 'Workspace Fixture Boundary'");
    Event::factory()->create(['id' => 2, 'title' => 'Concert', 'starts_at' => CarbonImmutable::parse($day.' 16:00', 'Europe/Berlin'), 'ends_at' => CarbonImmutable::parse($day.' 17:00', 'Europe/Berlin')]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(6.9603, 50.9375), 4326)::geography WHERE id = 2');

    return ['date' => $day, 'constraints' => ['window_start' => "{$day}T09:00:00{$offset}", 'window_end' => "{$day}T20:00:00{$offset}"], 'slots' => [
        ['id' => 'spot:1', 'type' => 'spot', 'name' => 'Park', 'start_at' => "{$day}T13:00:00{$offset}", 'end_at' => "{$day}T14:00:00{$offset}", 'start_time' => '13:00', 'end_time' => '14:00', 'swappable' => true],
        ['id' => 'event:2', 'type' => 'event', 'name' => 'Concert', 'start_at' => "{$day}T16:00:00{$offset}", 'end_at' => "{$day}T17:00:00{$offset}", 'start_time' => '16:00', 'end_time' => '17:00', 'swappable' => true],
    ]];
}

function seedWorkspaceProposal($test, User $user): array
{
    app(PrivatePlanCache::class)->putHandoff($user, 'test-token', workspacePlan());

    return $test->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'test-token'])->assertOk()->json();
}

test('workspace is authenticated and isolated', function () {
    $this->getJson('/composer/workspace')->assertUnauthorized();
    $a = User::factory()->onboarded()->create();
    $b = User::factory()->onboarded()->create();
    $this->actingAs($a)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'My private question'])->assertOk();
    $this->actingAs($b)->getJson('/composer/workspace')->assertOk()->assertDontSee('My private question');
    $this->actingAs($a)->getJson('/composer/workspace')->assertSee('My private question');
});

test('workspace rejects stale writes and untrusted fields without losing messages', function () {
    $this->actingAs(User::factory()->onboarded()->create());
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'First'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'message', 'text' => 'Stale'])->assertConflict();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'message', 'text' => 'Forged', 'plans' => []])->assertUnprocessable();
    $this->getJson('/composer/workspace')->assertSee('First')->assertDontSee('Stale')->assertDontSee('Forged');
});

test('proposal requires approval and survives fresh conversations and reload', function () {
    $user = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $user);
    expect($state['plans'])->toBeEmpty();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertOk()->assertJsonCount(1, 'plans');
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'fresh'])->assertOk()->assertJsonCount(2, 'conversations');
    $this->getJson('/composer/workspace')->assertJsonCount(1, 'plans')->assertJsonCount(1, 'versions');
});

test('overlapping edits and fixed event time changes never mutate the draft', function () {
    seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'spot:1', 'start' => '15:30', 'end' => '16:30'])->assertUnprocessable();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'event:2', 'start' => '18:00', 'end' => '19:00'])->assertUnprocessable();
    $this->getJson('/composer/workspace')->assertJsonPath('revision', 1)->assertJsonPath('draft.slots.0.start_time', '13:00');
});

test('routine approval is opt in and cancellation requires explicit confirmation with undo', function () {
    seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'approval', 'mode' => 'routine'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 3, 'action' => 'edit', 'id' => 'spot:1', 'start' => '12:00', 'end' => '13:00'])->assertOk()->assertJsonPath('draft', null);
    $this->postJson('/composer/workspace', ['revision' => 4, 'action' => 'remove', 'id' => 'spot:1'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 5, 'action' => 'remove', 'id' => 'event:2'])->assertOk()->assertJsonCount(1, 'plans')->assertJsonCount(0, 'draft.slots');
    $this->postJson('/composer/workspace', ['revision' => 6, 'action' => 'approve'])->assertUnprocessable();
    $this->postJson('/composer/workspace', ['revision' => 6, 'action' => 'approve', 'cancel' => true])->assertOk()->assertJsonCount(0, 'plans');
    $this->postJson('/composer/workspace', ['revision' => 7, 'action' => 'undo'])->assertOk()->assertJsonCount(1, 'plans');
});

test('appointment overlap is blocked and history deletion leaves plans intact', function () {
    seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'appointment', 'name' => 'Dentist', 'start' => '13:30', 'end' => '14:30'])->assertUnprocessable();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'delete_history'])->assertOk()->assertJsonCount(1, 'plans')->assertJsonCount(1, 'conversations')->assertJsonCount(0, 'conversations.0.messages');
});

test('adding a place finds a free interval after opening instead of a dead end', function () {
    $user = User::factory()->onboarded()->create();
    $day = now('Europe/Berlin')->addDay();
    $spot = Spot::factory()->create(['name' => 'Lunch Cafe', 'category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95, 'opening_hours' => [strtolower($day->format('D')) => [['12:00', '18:00']]]]);
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'appointment', 'date' => $day->format('Y-m-d'), 'name' => 'Lunch meeting', 'start' => '12:00', 'end' => '13:00'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'add', 'candidate' => "spot:{$spot->id}"])->assertOk()->assertJsonPath('draft.slots.1.start_time', '13:00')->assertJsonPath('draft.slots.1.end_time', '14:00');
});

test('today reads current revisions and dismissing its summary preserves saved plans', function () {
    $user = User::factory()->onboarded()->create();
    $plan = workspacePlan();
    $plan['appointment_revision'] = app(AppointmentRepository::class)->revision($user, Constraints::fromArray($plan['constraints']));
    app(TodayPlanStore::class)->save($user, $plan, 'Tomorrow');
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'spot:1', 'start' => '12:00', 'end' => '13:00'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'approve'])->assertOk();
    expect(app(TodayPlanStore::class)->get($user)['slots'][0]['start_time'])->toBe('12:00');
    app(TodayPlanStore::class)->forget($user);
    expect(app(TodayPlanStore::class)->get($user))->toBeNull();
    $this->getJson('/composer/workspace')->assertJsonCount(1, 'plans');
});

test('catalogue handoffs cannot be taken from another user', function () {
    $owner = User::factory()->onboarded()->create();
    app(PrivatePlanCache::class)->putHandoff($owner, 'private-token', workspacePlan());
    $this->actingAs(User::factory()->onboarded()->create())->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'private-token'])->assertUnprocessable();
});

test('composer guidance excludes unpublished expired and unverified sources', function () {
    $attributes = [
        'title' => 'Reviewed fixture guide', 'description' => 'Reviewed fixture content.',
        'is_published' => true, 'jurisdiction' => 'de-nrw-cologne',
        'review_status' => 'approved', 'source_verification' => 'dual_source',
        'reviewed_by' => 'expadu_content_owner', 'content_version' => 'review-fixture-v1',
        'verified_at' => now()->subDay(), 'review_due_at' => now()->addMonth(),
        'legal_sources' => [
            ['kind' => 'primary', 'label' => 'Official source', 'url' => 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html'],
            ['kind' => 'implementation', 'label' => 'City source', 'url' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html'],
        ],
    ];
    Task::factory()->create($attributes + ['key' => 'fixture-current']);
    Task::factory()->create(array_replace($attributes, ['key' => 'fixture-expired', 'review_due_at' => now()->subDay()]));
    Task::factory()->create(array_replace($attributes, ['key' => 'fixture-unpublished', 'is_published' => false]));
    Task::factory()->create(array_replace($attributes, ['key' => 'fixture-unverified', 'legal_sources' => []]));
    $this->actingAs(User::factory()->onboarded()->create())->getJson('/composer/guidance?query=fixture')
        ->assertOk()->assertJsonCount(1, 'guides')->assertJsonPath('guides.0.key', 'fixture-current');
});

test('explicit day entry never adds a personal plan to the previously focused day', function () {
    seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertSuccessful();
    $date = now('Europe/Berlin')->addDays(2)->format('Y-m-d');
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'appointment', 'date' => $date, 'name' => 'Coffee', 'location' => 'Ehrenfeld', 'start' => '15:00', 'end' => '16:00'])
        ->assertSuccessful()->assertJsonPath('draft.date', $date)->assertJsonPath('draft.slots.0.location', 'Ehrenfeld');
});

test('personal location and time are editable while fixed catalogue events stay protected', function () {
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->actingAs(User::factory()->onboarded()->create());
    $state = $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'appointment', 'date' => $date, 'name' => 'Coffee', 'location' => 'Mülheim', 'start' => '15:00', 'end' => '16:00'])->assertSuccessful()->json();
    $id = $state['draft']['slots'][0]['id'];
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => $id, 'location' => 'Ehrenfeld', 'start' => '16:00', 'end' => '17:00'])->assertSuccessful()
        ->assertJsonPath('draft.slots.0.location', 'Ehrenfeld')->assertJsonPath('draft.slots.0.start_time', '16:00')->assertJsonPath('draft.feasibility_checked', false);
});

test('approval rechecks a new catalogue selection without losing the draft or revision', function () {
    $user = User::factory()->onboarded()->create();
    $place = Spot::factory()->create(['category' => 'park', 'lat' => 50.94, 'lng' => 6.95]);
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->actingAs($user)->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => $date, 'candidate' => 'spot:'.$place->id,
    ])->assertSuccessful();
    $place->update(['is_recommendable' => false]);

    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertUnprocessable();
    $state = $this->getJson('/composer/workspace')->assertSuccessful()->json();
    expect($state['revision'])->toBe(1)
        ->and($state['plans'])->toBeEmpty()
        ->and($state['draft']['slots'][0]['id'])->toBe('spot:'.$place->id)
        ->and($state['draft']['slots'][0]['recommendation_status'])->toBe('unavailable');
});

test('saved unavailable places retain history and permit unrelated personal plan edits', function () {
    $user = User::factory()->onboarded()->create();
    $place = Spot::factory()->create(['category' => 'park', 'lat' => 50.94, 'lng' => 6.95]);
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->actingAs($user)->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => $date, 'candidate' => 'spot:'.$place->id,
        'start' => '13:00', 'end' => '14:00',
    ])->assertSuccessful();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertSuccessful();
    $saved = ComposerWorkspace::query()->where('user_id', $user->id)->firstOrFail()->state;
    $place->update(['is_recommendable' => false]);

    $read = $this->getJson('/composer/workspace')->assertSuccessful()->json();
    expect($read['plans'][$date]['slots'][0]['recommendation_status'])->toBe('unavailable')
        ->and($read['plans'][$date]['slots'][0]['start_time'])->toBe('13:00')
        ->and($read['plans'][$date]['slots'][0]['cost_tier'])->toBe('unknown')
        ->and(ComposerWorkspace::query()->where('user_id', $user->id)->firstOrFail()->state)->toBe($saved);
    $this->postJson('/composer/workspace', [
        'revision' => 2, 'action' => 'appointment', 'date' => $date,
        'name' => 'Meet a friend', 'start' => '16:00', 'end' => '17:00',
    ])->assertSuccessful();
    $this->postJson('/composer/workspace', ['revision' => 3, 'action' => 'approve'])->assertSuccessful()
        ->assertJsonCount(2, "plans.{$date}.slots")
        ->assertJsonPath("plans.{$date}.slots.0.recommendation_status", 'unavailable');
});

test('canonical saved aliases cannot be added twice and still support removal', function () {
    $user = User::factory()->onboarded()->create();
    $canonical = Spot::factory()->create(['category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95]);
    $alias = Spot::factory()->create(['category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95]);
    DB::table('spots')->where('id', $alias->id)->update(['canonical_spot_id' => $canonical->id]);
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->actingAs($user)->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => $date, 'candidate' => 'spot:'.$alias->id,
    ])->assertSuccessful()->assertJsonPath('draft.slots.0.id', 'spot:'.$canonical->id);
    $this->postJson('/composer/workspace', [
        'revision' => 1, 'action' => 'add', 'date' => $date, 'candidate' => 'spot:'.$canonical->id,
    ])->assertUnprocessable();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'remove', 'id' => 'spot:'.$alias->id])
        ->assertSuccessful()->assertJsonCount(0, 'draft.slots');
});

test('new saved selections must respect category companions and time constraints', function (string $category, array $constraints, string $start, string $end) {
    $user = User::factory()->onboarded()->create();
    $plan = workspacePlan();
    $plan['slots'] = [array_replace($plan['slots'][0], [
        'start_at' => CarbonImmutable::parse($plan['date'].' '.$start, 'Europe/Berlin')->toIso8601String(),
        'end_at' => CarbonImmutable::parse($plan['date'].' '.$end, 'Europe/Berlin')->toIso8601String(),
        'start_time' => $start, 'end_time' => $end,
    ])];
    $plan['constraints'] = array_replace($plan['constraints'], $constraints);
    Spot::query()->whereKey(1)->update(['category' => $category]);
    app(PrivatePlanCache::class)->putHandoff($user, 'constrained-token', $plan);
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'constrained-token'])->assertSuccessful();

    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertUnprocessable();
    $this->getJson('/composer/workspace')->assertSuccessful()
        ->assertJsonPath('revision', 1)->assertJsonCount(0, 'plans')
        ->assertJsonPath('draft.slots.0.recommendation_status', 'unavailable');
})->with([
    'children cannot be routed through a bar' => ['bar', ['companions' => 'kids'], '13:00', '14:00'],
    'the selected category still applies' => ['cafe', ['categories' => ['park']], '13:00', '14:00'],
    'before the agreed time window' => ['park', [], '08:00', '09:00'],
    'after the agreed time window' => ['park', [], '20:00', '21:00'],
]);

test('an explicitly shortened visit may fit a window shorter than the default duration', function () {
    $user = User::factory()->onboarded()->create();
    $plan = workspacePlan();
    $plan['slots'] = [$plan['slots'][0]];
    $plan['constraints']['window_start'] = $plan['slots'][0]['start_at'];
    $plan['constraints']['window_end'] = $plan['slots'][0]['end_at'];
    app(PrivatePlanCache::class)->putHandoff($user, 'shortened-token', $plan);
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'shortened-token'])->assertSuccessful();

    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'approve'])->assertSuccessful()
        ->assertJsonPath("plans.{$plan['date']}.slots.0.recommendation_status", 'available');
});

test('manual additions measure the radius from the saved plan origin', function () {
    $user = User::factory()->onboarded()->create();
    $plan = workspacePlan();
    $plan['slots'] = [$plan['slots'][0]];
    $plan['origin'] = [50.99, 6.99];
    $plan['constraints']['radius_km'] = 1;
    Spot::query()->whereKey(1)->update(['lat' => 50.99, 'lng' => 6.99]);
    $far = Spot::factory()->create(['id' => 3, 'category' => 'park', 'lat' => 50.9375, 'lng' => 6.9603, 'tags' => ['opening_hours' => 'Mo-Su 00:00-24:00']]);
    $near = Spot::factory()->create(['id' => 4, 'category' => 'park', 'lat' => 50.991, 'lng' => 6.991, 'tags' => ['opening_hours' => 'Mo-Su 00:00-24:00']]);
    app(PrivatePlanCache::class)->putHandoff($user, 'origin-token', $plan);
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'origin-token'])->assertSuccessful();

    $this->postJson('/composer/workspace', [
        'revision' => 1, 'action' => 'add', 'candidate' => 'spot:'.$far->id, 'start' => '15:00', 'end' => '16:00',
    ])->assertUnprocessable();
    $this->postJson('/composer/workspace', [
        'revision' => 1, 'action' => 'add', 'candidate' => 'spot:'.$near->id, 'start' => '15:00', 'end' => '16:00',
    ])->assertSuccessful()->assertJsonPath('draft.slots.1.recommendation_status', 'available');
});

test('automatic place timing for today never starts in the past', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 16:00:30', 'Europe/Berlin'));
    workspacePlan();
    $this->actingAs(User::factory()->onboarded()->create());

    $this->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => '2026-10-05', 'candidate' => 'spot:1',
    ])->assertSuccessful()->assertJsonPath('draft.slots.0.start_time', '16:01');
});

test('automatic place timing uses the selected plan window', function (string $start, string $end) {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 16:00:30', 'Europe/Berlin'));
    $plan = workspacePlan();
    $plan['slots'] = [];
    $plan['constraints']['window_start'] = $plan['date'].'T'.$start.':00+02:00';
    $plan['constraints']['window_end'] = $plan['date'].'T'.$end.':00+02:00';
    $user = User::factory()->onboarded()->create();
    app(PrivatePlanCache::class)->putHandoff($user, 'window-token', $plan);
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'window-token'])->assertSuccessful();

    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'add', 'candidate' => 'spot:1'])
        ->assertSuccessful()->assertJsonPath('draft.slots.0.start_time', $start);
})->with([
    'afternoon' => ['14:00', '18:00'],
    'early morning' => ['08:00', '09:30'],
]);

test('automatic timing refuses a visit that cannot fit the remaining day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 23:30:00', 'Europe/Berlin'));
    workspacePlan();
    $this->actingAs(User::factory()->onboarded()->create());

    $this->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => '2026-10-05', 'candidate' => 'spot:1',
    ])->assertUnprocessable();
    $this->getJson('/composer/workspace')->assertOk()->assertJsonPath('draft', null)->assertJsonPath('revision', 0);
});
