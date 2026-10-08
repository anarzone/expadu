<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

function settledResident(): User
{
    return User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_date' => now()->subYears(2),
    ]);
}

test('a general settled declaration does not complete tasks or establish permanent status', function () {
    $user = settledResident();

    // Neither a task key suffix nor a general declaration proves completion.
    $anmeldung = Task::factory()->create(['key' => 'nee.anmeldung', 'is_published' => true]);
    $bank = Task::factory()->create(['key' => 'core.bank_account', 'is_published' => true]);
    $longGame = Task::factory()->create(['key' => 'shared.long_game', 'type' => 'info', 'is_published' => true]);
    $schufa = Task::factory()->create(['key' => 'shared.schufa', 'type' => 'info', 'is_published' => true]);

    foreach ([$anmeldung, $bank, $longGame, $schufa] as $task) {
        UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);
    }

    $this->actingAs($user)->post(route('bureaucracy.settle'))->assertRedirect();

    $row = fn (Task $t) => $user->userTasks()->where('task_id', $t->id)->first();

    expect($row($anmeldung)->status)->toBe(TaskStatus::NotStarted)
        ->and($row($bank)->status)->toBe(TaskStatus::NotStarted)
        ->and($row($anmeldung)->completed_at)->toBeNull()
        ->and($row($bank)->completed_at)->toBeNull();
    expect($row($longGame)->is_applicable)->toBeTrue();
    // Evergreen reference (Schufa) → left untouched.
    expect($row($schufa)->is_applicable)->toBeTrue()
        ->and($row($schufa)->status)->toBe(TaskStatus::NotStarted);
    // The product declaration is retained without inventing a legal-title fact.
    expect($user->fresh()->profile_attributes['arrival_setup_declared_at'] ?? null)->toBe(now()->toDateString())
        ->and($user->fresh()->profile_attributes['settled_at'] ?? null)->toBeNull();
});

test('the settle action only touches the calling user', function () {
    $me = settledResident();
    $other = settledResident();
    $anmeldung = Task::factory()->create(['key' => 'nee.anmeldung', 'is_published' => true]);
    UserTask::create(['user_id' => $me->id, 'task_id' => $anmeldung->id]);
    $theirs = UserTask::create(['user_id' => $other->id, 'task_id' => $anmeldung->id]);

    $this->actingAs($me)->post(route('bureaucracy.settle'));

    expect($theirs->fresh()->status)->toBe(TaskStatus::NotStarted);
});

test('tenure and a general declaration never produce a permanent-residence suggestion or status', function () {
    $user = settledResident();
    $anmeldung = Task::factory()->create(['key' => 'nee.anmeldung', 'is_published' => true]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $anmeldung->id]);

    $this->actingAs($user)->get(route('bureaucracy.legacy'))
        ->assertInertia(fn ($page) => $page->where('settledSuggestion', false)->where('settled', false));

    $this->actingAs($user)->post(route('bureaucracy.settle'));

    $this->actingAs($user)->get(route('bureaucracy.legacy'))
        ->assertInertia(fn ($page) => $page
            ->where('settledSuggestion', false)
            ->where('settled', false)
            ->where('phases.current', fn ($phase) => $phase !== 'permanent'));
});

test('a recent arrival never sees the settled suggestion', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_date' => now()->subDays(20),
    ]);
    $anmeldung = Task::factory()->create(['key' => 'nee.anmeldung', 'is_published' => true]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $anmeldung->id]);

    $this->actingAs($user)->get(route('bureaucracy.legacy'))
        ->assertInertia(fn ($page) => $page->where('settledSuggestion', false));
});

test('an opted-out info card moves to Not applicable, not the info lane', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_date' => now()->subYears(2),
        'profile_attributes' => ['housing_status' => 'long_term'],
    ]);
    // An info-type card that genuinely applies (so it reaches a lane), then opted out.
    $info = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.opted-out-info',
        'type' => 'info',
        'is_published' => true,
        'applies_if' => [[]],
    ]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $info->id, 'is_applicable' => false]);

    $this->actingAs($user)->get(route('bureaucracy.legacy'))->assertInertia(function ($page) {
        $props = $page->toArray()['props'];
        expect(collect($props['tasks']['not_applicable'])->pluck('key'))->toContain('fixture.opted-out-info');
        expect(collect($props['tasks']['info'])->pluck('key'))->not->toContain('fixture.opted-out-info');

        return true;
    });
});

test('an unresolved old deadline remains overdue rather than being softened by age', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_date' => now()->subYears(3),
        'profile_attributes' => ['housing_status' => 'long_term'],
    ]);
    // Due 14 days after a 3-year-old arrival → ~1080 days overdue.
    $lapsed = Task::factory()->approvedFixture()->create([
        'key' => 'x.lapsed',
        'title' => 'Long Lapsed Task',
        'is_published' => true,
        'applies_if' => [[]],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 14,
    ]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $lapsed->id]);

    $this->actingAs($user)->get(route('bureaucracy.legacy'))->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks']['active'])->firstWhere('key', 'x.lapsed');
        expect($card)->not->toBeNull()
            ->and($card['deadline_tier'])->toBe('overdue');

        return true;
    });
});
