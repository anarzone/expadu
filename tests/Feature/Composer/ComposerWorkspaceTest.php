<?php

use App\Composer\AppointmentRepository;
use App\Composer\Constraints;
use App\Composer\PrivatePlanCache;
use App\Composer\TodayPlanStore;
use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerWorkspace;
use App\Models\Event;
use App\Models\Spot;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    // Commands that no longer exist are refused outright.
    foreach (['approve', 'discard', 'approval', 'date'] as $retired) {
        $this->postJson('/composer/workspace', ['revision' => 1, 'action' => $retired])->assertUnprocessable()->assertJsonValidationErrors('action');
    }
    $this->getJson('/composer/workspace')->assertSee('First')->assertDontSee('Stale')->assertDontSee('Forged')->assertJsonPath('revision', 1);
});

test('a proposal goes straight into the plans with its change rows and Undo', function () {
    $user = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $user);
    $date = array_key_first($state['plans']);
    $weekday = CarbonImmutable::parse($date, 'Europe/Berlin')->isoFormat('dddd');
    $messages = collect($state['conversations'][0]['messages']);
    $change = $messages->firstWhere('kind', 'change');

    expect($state['plans'][$date]['slots'])->toHaveCount(2)
        ->and($state)->not->toHaveKeys(['draft', 'approval', 'versions'])
        ->and($change['text'])->toBe("{$weekday} is in your plans.")
        ->and(collect($change['rows'])->pluck('kind')->all())->toBe(['added', 'added'])
        ->and($state['changes'][0]['undoable'])->toBeTrue()
        ->and($messages->firstWhere('kind', 'plan')['plan']['slots'])->toHaveCount(2);

    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'fresh'])->assertOk()->assertJsonCount(2, 'conversations');
    $this->getJson('/composer/workspace')->assertJsonCount(1, 'plans')->assertJsonCount(1, 'changes');
    // Undo needs no confirmation and removes the new day again.
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'undo', 'change' => $change['change_id']])->assertOk()
        ->assertJsonCount(0, 'plans')->assertJsonPath('changes.0.undoable', false);
});

test('a moved item is said as it is now, old to new', function () {
    seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $state = $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'spot:1', 'start' => '14:00', 'end' => '15:00'])->assertOk()->json();
    $change = collect($state['conversations'][0]['messages'])->where('kind', 'change')->last();

    expect($change['text'])->toBe('Park is now 14:00–15:00.')
        ->and($change['rows'])->toBe([['kind' => 'moved', 'id' => 'spot:1', 'title' => 'Park', 'type' => 'spot', 'from' => '13:00–14:00', 'to' => '14:00–15:00']])
        // One plan card for the day; it shows the day as it is.
        ->and(collect($state['conversations'][0]['messages'])->where('kind', 'plan'))->toHaveCount(1)
        ->and(collect($state['conversations'][0]['messages'])->firstWhere('kind', 'plan')['plan']['slots'][0]['start_time'])->toBe('14:00');
});

test('a clash or a fixed time changes nothing and says why', function () {
    $user = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $user);
    $date = array_key_first($state['plans']);
    $before = ComposerWorkspace::query()->where('user_id', $user->id)->sole()->state['plans'];

    $clash = $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'spot:1', 'start' => '15:30', 'end' => '16:30'])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'clash')->assertJsonPath('workspace.revision', 1)
        ->assertJsonPath("workspace.plans.{$date}.slots.0.start_time", '13:00');
    expect($clash->json('message'))->toBe('Park overlaps Concert.');
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'event:2', 'start' => '18:00', 'end' => '19:00'])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'fixed_time');
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'appointment', 'date' => $date, 'name' => 'Dentist', 'start' => '13:30', 'end' => '14:30'])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'clash');

    // Nothing is written: not the plan, not the conversation, not the revision.
    $row = ComposerWorkspace::query()->where('user_id', $user->id)->sole();
    expect($row->state['plans'])->toBe($before)
        ->and($row->state['changes'])->toHaveCount(1)
        ->and($row->revision)->toBe(1)
        ->and(collect($row->state['conversations'][0]['messages'])->where('kind', 'refusal'))->toBeEmpty();
});

test('a time outside the listed hours changes nothing', function () {
    $day = now('Europe/Berlin')->addDay();
    $spot = Spot::factory()->create(['name' => 'Lunch Cafe', 'category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95, 'opening_hours' => [strtolower($day->format('D')) => [['12:00', '18:00']]]]);
    $this->actingAs(User::factory()->onboarded()->create())->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => $day->format('Y-m-d'), 'candidate' => "spot:{$spot->id}", 'start' => '19:00', 'end' => '20:00',
    ])->assertUnprocessable()->assertJsonPath('refusal.code', 'outside_hours')->assertJsonPath('workspace.plans', []);
});

test('text never changes a plan by itself', function () {
    $user = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $user);
    $plans = ComposerWorkspace::query()->where('user_id', $user->id)->sole()->state['plans'];
    foreach (['Remove the park', 'Cancel my day', 'undo', 'Move the concert to 20:00'] as $i => $text) {
        $this->postJson('/composer/workspace', ['revision' => 1 + $i, 'action' => 'message', 'text' => $text])->assertOk();
    }
    $stored = ComposerWorkspace::query()->where('user_id', $user->id)->sole()->state;
    expect($stored['plans'])->toBe($plans)->and($stored['changes'])->toHaveCount(1)
        ->and(collect($stored['changes'])->whereNotNull('undone_at'))->toBeEmpty()
        ->and($state['changes'])->toHaveCount(1);
});

test('each change has its own Undo, newest first per day', function () {
    $user = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $user);
    $day = array_key_first($state['plans']);
    $other = CarbonImmutable::parse($day)->addDay()->format('Y-m-d');
    $state = $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'spot:1', 'start' => '14:00', 'end' => '15:00'])->assertOk()->json();
    $state = $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'appointment', 'date' => $other, 'name' => 'Coffee', 'start' => '10:00', 'end' => '11:00'])->assertOk()->json();
    [$planned, $moved, $coffee] = array_column($state['changes'], 'id');

    // The first day's older change waits for its newer one; the other day is independent.
    $this->postJson('/composer/workspace', ['revision' => 3, 'action' => 'undo', 'change' => $planned])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'changed_since');
    $this->postJson('/composer/workspace', ['revision' => 3, 'action' => 'undo', 'change' => $moved])->assertOk()
        ->assertJsonPath("plans.{$day}.slots.0.start_time", '13:00')->assertJsonPath("plans.{$other}.slots.0.name", 'Coffee');
    $this->postJson('/composer/workspace', ['revision' => 4, 'action' => 'undo', 'change' => $planned])->assertOk()
        ->assertJsonMissingPath("plans.{$day}")->assertJsonPath("plans.{$other}.slots.0.name", 'Coffee');
    // An undone change can't be undone twice; without an id, the newest change goes.
    $this->postJson('/composer/workspace', ['revision' => 5, 'action' => 'undo', 'change' => $moved])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'nothing_to_undo');
    $this->postJson('/composer/workspace', ['revision' => 5, 'action' => 'undo'])->assertOk()->assertJsonCount(0, 'plans');
    expect($coffee)->toBeString();
});

test('an Undo belongs to the person who made the change', function () {
    $owner = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $owner);
    $change = $state['changes'][0]['id'];
    $stranger = User::factory()->onboarded()->create();
    $this->actingAs($stranger)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'undo', 'change' => $change])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'nothing_to_undo');

    expect(ComposerWorkspace::query()->where('user_id', $owner->id)->sole()->state['plans'])->toHaveCount(1)
        ->and(ComposerWorkspace::query()->where('user_id', $owner->id)->sole()->state['changes'][0]['undone_at'])->toBeNull();
});

test('undo and changes reject a stale revision without touching the plan', function () {
    $user = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $user);
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'undo', 'change' => $state['changes'][0]['id']])->assertConflict();
    $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'remove', 'id' => 'spot:1'])->assertConflict();
    expect(ComposerWorkspace::query()->where('user_id', $user->id)->sole()->state['plans'][array_key_first($state['plans'])]['slots'])->toHaveCount(2);
});

test('ticking an item off does not block its Undo', function () {
    $user = User::factory()->onboarded()->create();
    $state = seedWorkspaceProposal($this, $user);
    $date = array_key_first($state['plans']);
    $row = ComposerWorkspace::query()->where('user_id', $user->id)->sole();
    $stored = $row->state;
    $stored['plans'][$date]['slots'][0]['completed_at'] = now('Europe/Berlin')->toIso8601String();
    $row->update(['state' => $stored]);

    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'undo'])->assertOk()->assertJsonCount(0, 'plans');
});

test('cancelling a day happens at once and its Undo brings it back', function () {
    $state = seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $date = array_key_first($state['plans']);
    $cancelled = $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'cancel_day', 'date' => $date])->assertOk()->assertJsonCount(0, 'plans')->json();
    $weekday = CarbonImmutable::parse($date, 'Europe/Berlin')->isoFormat('dddd');
    expect(collect($cancelled['conversations'][0]['messages'])->where('kind', 'change')->last()['text'])->toBe("{$weekday}’s plan is cancelled.");
    $this->postJson('/composer/workspace', ['revision' => 2, 'action' => 'undo'])->assertOk()->assertJsonCount(2, "plans.{$date}.slots");
    // Removing the last item cancels the day the same way.
    $this->postJson('/composer/workspace', ['revision' => 3, 'action' => 'remove', 'id' => 'spot:1'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 4, 'action' => 'remove', 'id' => 'event:2'])->assertOk()->assertJsonCount(0, 'plans');
});

test('history deletion leaves plans intact', function () {
    seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'delete_history'])->assertOk()->assertJsonCount(1, 'plans')->assertJsonCount(1, 'conversations')->assertJsonCount(0, 'conversations.0.messages');
});

test('an unfinished draft from before is kept to reapply, never applied by itself', function () {
    $user = User::factory()->onboarded()->create();
    $plan = workspacePlan();
    $date = $plan['date'];
    $earlier = ['date' => $date, 'slots' => [$plan['slots'][1]], 'constraints' => $plan['constraints']];
    $legacy = ['approval' => 'ask', 'active' => 'initial', 'focus' => $date, 'draft' => $plan,
        'plans' => [$date => $earlier],
        'versions' => [['id' => (string) Str::uuid(), 'date' => $date, 'before' => null, 'after' => $earlier, 'created_at' => now()->subHour()->toIso8601String(), 'undone' => false]],
        'conversations' => [['id' => 'initial', 'title' => 'Earlier', 'created_at' => now()->subHour()->toIso8601String(), 'messages' => [
            ['id' => 'm1', 'role' => 'assistant', 'text' => '', 'created_at' => now()->subHour()->toIso8601String(), 'kind' => 'plan', 'plan' => $earlier],
        ]]]];
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'state' => $legacy, 'revision' => 4]);

    $state = $this->actingAs($user)->getJson('/composer/workspace')->assertOk()->json();
    $draft = collect($state['conversations'][0]['messages'])->firstWhere('kind', 'earlier_draft');
    expect($state['plans'][$date]['slots'])->toHaveCount(1)
        ->and($draft['plan']['slots'])->toHaveCount(2)
        ->and($draft['text'])->toContain('It isn’t in your plans')
        ->and($state['changes'][0]['undoable'])->toBeTrue()
        ->and(collect($state['conversations'][0]['messages'])->firstWhere('kind', 'plan')['plan']['slots'])->toHaveCount(1);

    $this->postJson('/composer/workspace', ['revision' => 4, 'action' => 'reapply', 'id' => $draft['draft_id']])->assertOk()
        ->assertJsonCount(2, "plans.{$date}.slots")->assertJsonCount(2, 'changes');
    $this->postJson('/composer/workspace', ['revision' => 5, 'action' => 'reapply', 'id' => $draft['draft_id']])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'already_reapplied');
    // Its Undo takes the day back to what it was before the reapply.
    $this->postJson('/composer/workspace', ['revision' => 5, 'action' => 'undo'])->assertOk()->assertJsonCount(1, "plans.{$date}.slots");
});

test('the upgrade migration moves stored workspaces once and makes open tabs reload', function () {
    $user = User::factory()->onboarded()->create();
    $legacy = ['approval' => 'routine', 'active' => 'initial', 'focus' => null, 'draft' => null, 'plans' => [], 'versions' => [],
        'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]]];
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'state' => $legacy, 'revision' => 7]);
    $migration = require database_path('migrations/2026_10_20_100000_upgrade_composer_workspaces_to_direct_apply.php');

    $migration->up();
    $row = ComposerWorkspace::query()->where('user_id', $user->id)->sole();
    expect($row->state['schema'])->toBe(WorkspaceUpgrade::SCHEMA)->and($row->revision)->toBe(8)
        ->and($row->state)->not->toHaveKeys(['approval', 'draft', 'versions']);
    $migration->up();
    expect(ComposerWorkspace::query()->where('user_id', $user->id)->sole()->revision)->toBe(8);
});

test('today reads current revisions and dismissing its summary preserves saved plans', function () {
    $user = User::factory()->onboarded()->create();
    $plan = workspacePlan();
    $plan['appointment_revision'] = app(AppointmentRepository::class)->revision($user, Constraints::fromArray($plan['constraints']));
    app(TodayPlanStore::class)->save($user, $plan, 'Tomorrow');
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => 'spot:1', 'start' => '12:00', 'end' => '13:00'])->assertOk();
    expect(app(TodayPlanStore::class)->get($user)['slots'][0]['start_time'])->toBe('12:00');
    app(TodayPlanStore::class)->forget($user);
    expect(app(TodayPlanStore::class)->get($user))->toBeNull();
    $this->getJson('/composer/workspace')->assertJsonCount(1, 'plans');
});

test('catalogue handoffs cannot be taken from another user', function () {
    $owner = User::factory()->onboarded()->create();
    app(PrivatePlanCache::class)->putHandoff($owner, 'private-token', workspacePlan());
    $this->actingAs(User::factory()->onboarded()->create())->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'private-token'])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'expired')->assertJsonPath('workspace.plans', []);
});

test('a page hands Composer its request by POST, once, never in the URL', function () {
    $this->post('/composer/entry', ['prompt' => 'x'])->assertRedirect('/login');
    $this->actingAs(User::factory()->onboarded()->create());
    $response = $this->post('/composer/entry', ['prompt' => '  Plan my Saturday near my appointment  ', 'about' => 'Events on Wed 30 Sept · 2 listed', 'panel' => 'plans']);
    $response->assertRedirect('/composer?panel=plans');
    expect($response->headers->get('Location'))->not->toContain('Saturday');

    $this->get('/composer?panel=plans')->assertInertia(fn ($page) => $page->component('composer')
        ->where('entry', ['prompt' => 'Plan my Saturday near my appointment', 'about' => 'Events on Wed 30 Sept · 2 listed']));
    $this->get('/composer')->assertInertia(fn ($page) => $page->where('entry', null));

    $this->post('/composer/entry', ['prompt' => str_repeat('a', 501)])->assertSessionHasErrors('prompt');
    $this->post('/composer/entry', ['prompt' => 'Hi', 'panel' => 'settings'])->assertSessionHasErrors('panel');
});

test('a hand-off waits for Composer itself, not the next request, and goes stale', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    // A request the previous page still had in flight doesn't take the words.
    $this->post('/composer/entry', ['prompt' => 'Something near my appointment']);
    $this->getJson('/composer/workspace')->assertOk();
    $this->get('/composer')->assertInertia(fn ($page) => $page->where('entry.prompt', 'Something near my appointment'));
    $this->get('/composer')->assertInertia(fn ($page) => $page->where('entry', null));

    // Nor does one that ends after the hand-off and saves the session as it was before it.
    $before = session()->all();
    $this->post('/composer/entry', ['prompt' => 'Something near my appointment']);
    session()->replace($before);
    $this->get('/composer')->assertInertia(fn ($page) => $page->where('entry.prompt', 'Something near my appointment'));

    // Words left waiting (the visit never came) aren't shown later.
    $this->post('/composer/entry', ['prompt' => 'An old thought']);
    $this->travel(6)->minutes();
    $this->get('/composer')->assertInertia(fn ($page) => $page->where('entry', null));
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
    $state = seedWorkspaceProposal($this, User::factory()->onboarded()->create());
    $first = array_key_first($state['plans']);
    $date = now('Europe/Berlin')->addDays(2)->format('Y-m-d');
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'appointment', 'date' => $date, 'name' => 'Coffee', 'location' => 'Ehrenfeld', 'start' => '15:00', 'end' => '16:00'])
        ->assertSuccessful()->assertJsonPath("plans.{$date}.slots.0.location", 'Ehrenfeld')->assertJsonCount(2, "plans.{$first}.slots");
});

test('personal location and time are editable while fixed catalogue events stay protected', function () {
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->actingAs(User::factory()->onboarded()->create());
    $state = $this->postJson('/composer/workspace', ['revision' => 0, 'action' => 'appointment', 'date' => $date, 'name' => 'Coffee', 'location' => 'Mülheim', 'start' => '15:00', 'end' => '16:00'])->assertSuccessful()->json();
    $id = $state['plans'][$date]['slots'][0]['id'];
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'edit', 'id' => $id, 'location' => 'Ehrenfeld', 'start' => '16:00', 'end' => '17:00'])->assertSuccessful()
        ->assertJsonPath("plans.{$date}.slots.0.location", 'Ehrenfeld')->assertJsonPath("plans.{$date}.slots.0.start_time", '16:00')->assertJsonPath("plans.{$date}.feasibility_checked", false);
});

test('adding a place finds a free interval after opening instead of a dead end', function () {
    $user = User::factory()->onboarded()->create();
    $day = now('Europe/Berlin')->addDay();
    $date = $day->format('Y-m-d');
    $spot = Spot::factory()->create(['name' => 'Lunch Cafe', 'category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95, 'opening_hours' => [strtolower($day->format('D')) => [['12:00', '18:00']]]]);
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'appointment', 'date' => $date, 'name' => 'Lunch meeting', 'start' => '12:00', 'end' => '13:00'])->assertOk();
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'add', 'candidate' => "spot:{$spot->id}"])->assertOk()
        ->assertJsonPath("plans.{$date}.slots.1.start_time", '13:00')->assertJsonPath("plans.{$date}.slots.1.end_time", '14:00');
});

test('a selection that is no longer available is refused and the plan stays', function () {
    $user = User::factory()->onboarded()->create();
    $place = Spot::factory()->create(['category' => 'park', 'lat' => 50.94, 'lng' => 6.95, 'is_recommendable' => false]);
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->actingAs($user)->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => $date, 'candidate' => 'spot:'.$place->id,
    ])->assertUnprocessable()->assertJsonPath('workspace.plans', [])->assertJsonPath('workspace.revision', 0);
});

test('saved unavailable places retain history and permit unrelated personal plan edits', function () {
    $user = User::factory()->onboarded()->create();
    $place = Spot::factory()->create(['category' => 'park', 'lat' => 50.94, 'lng' => 6.95]);
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $this->actingAs($user)->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => $date, 'candidate' => 'spot:'.$place->id,
        'start' => '13:00', 'end' => '14:00',
    ])->assertSuccessful();
    $saved = ComposerWorkspace::query()->where('user_id', $user->id)->firstOrFail()->state;
    $place->update(['is_recommendable' => false]);

    $read = $this->getJson('/composer/workspace')->assertSuccessful()->json();
    expect($read['plans'][$date]['slots'][0]['recommendation_status'])->toBe('unavailable')
        ->and($read['plans'][$date]['slots'][0]['start_time'])->toBe('13:00')
        ->and($read['plans'][$date]['slots'][0]['cost_tier'])->toBe('unknown')
        ->and(ComposerWorkspace::query()->where('user_id', $user->id)->firstOrFail()->state)->toBe($saved);
    $this->postJson('/composer/workspace', [
        'revision' => 1, 'action' => 'appointment', 'date' => $date,
        'name' => 'Meet a friend', 'start' => '16:00', 'end' => '17:00',
    ])->assertSuccessful()
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
    ])->assertSuccessful()->assertJsonPath("plans.{$date}.slots.0.id", 'spot:'.$canonical->id);
    $this->postJson('/composer/workspace', [
        'revision' => 1, 'action' => 'add', 'date' => $date, 'candidate' => 'spot:'.$canonical->id,
    ])->assertUnprocessable()->assertJsonPath('refusal.code', 'duplicate');
    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'remove', 'id' => 'spot:'.$alias->id])
        ->assertSuccessful()->assertJsonCount(0, 'plans');
});

test('composed selections must respect category companions and time constraints', function (string $category, array $constraints, string $start, string $end) {
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
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'constrained-token'])
        ->assertUnprocessable()->assertJsonPath('refusal.code', 'unavailable')->assertJsonPath('workspace.plans', []);
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
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'shortened-token'])->assertSuccessful()
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
    ])->assertUnprocessable()->assertJsonPath('refusal.code', 'outside_hours');
    $this->postJson('/composer/workspace', [
        'revision' => 1, 'action' => 'add', 'candidate' => 'spot:'.$near->id, 'start' => '15:00', 'end' => '16:00',
    ])->assertSuccessful()->assertJsonPath("plans.{$plan['date']}.slots.1.recommendation_status", 'available');
});

test('automatic place timing for today never starts in the past', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 16:00:30', 'Europe/Berlin'));
    workspacePlan();
    $this->actingAs(User::factory()->onboarded()->create());

    $this->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => '2026-10-05', 'candidate' => 'spot:1',
    ])->assertSuccessful()->assertJsonPath('plans.2026-10-05.slots.0.start_time', '16:01');
});

test('automatic place timing uses the selected plan window', function (string $start, string $end, string $busy) {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 16:00:30', 'Europe/Berlin'));
    $plan = workspacePlan();
    $date = $plan['date'];
    // Something already ends the window, so the free time starts with it.
    $plan['slots'] = [['id' => 'personal:busy', 'type' => 'personal', 'name' => 'Busy', 'swappable' => false,
        'start_at' => "{$date}T{$busy}:00+02:00", 'end_at' => "{$date}T{$end}:00+02:00", 'start_time' => $busy, 'end_time' => $end]];
    $plan['constraints']['window_start'] = $date.'T'.$start.':00+02:00';
    $plan['constraints']['window_end'] = $date.'T'.$end.':00+02:00';
    $user = User::factory()->onboarded()->create();
    app(PrivatePlanCache::class)->putHandoff($user, 'window-token', $plan);
    $this->actingAs($user)->postJson('/composer/workspace', ['revision' => 0, 'action' => 'proposal', 'token' => 'window-token'])->assertSuccessful();

    $this->postJson('/composer/workspace', ['revision' => 1, 'action' => 'add', 'candidate' => 'spot:1'])
        ->assertSuccessful()->assertJsonPath("plans.{$date}.slots.0.start_time", $start);
})->with([
    'afternoon' => ['14:00', '18:00', '17:00'],
    'early morning' => ['08:00', '09:30', '09:15'],
]);

test('automatic timing refuses a visit that cannot fit the remaining day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 23:30:00', 'Europe/Berlin'));
    workspacePlan();
    $this->actingAs(User::factory()->onboarded()->create());

    $this->postJson('/composer/workspace', [
        'revision' => 0, 'action' => 'add', 'date' => '2026-10-05', 'candidate' => 'spot:1',
    ])->assertUnprocessable()->assertJsonPath('refusal.code', 'no_free_time')->assertJsonPath('workspace.plans', []);
});
