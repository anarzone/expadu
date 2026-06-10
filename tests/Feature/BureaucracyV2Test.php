<?php

use App\Models\Task;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserTask;
use Symfony\Component\Yaml\Yaml;

// ── Dependency blocking ────────────────────────────────────────────────

test('a task is blocked while its dependency is incomplete', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $anmeldung = Task::factory()->create([
        'key' => 't.anmeldung',
        'title' => 'Anmeldung',
        'situation' => ['non_eu_employee'],
    ]);
    $bank = Task::factory()->create([
        'key' => 't.bank',
        'title' => 'Open a bank account',
        'situation' => ['non_eu_employee'],
        'depends_on' => ['t.anmeldung'],
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) use ($bank) {
        $cards = collect($page->toArray()['props']['tasks'])
            ->flatten(1)
            ->keyBy('task_id');

        expect($cards[$bank->id]['blocked'])->toBeTrue();
        expect($cards[$bank->id]['blocked_by'])->toContain('Anmeldung');

        return true;
    });
});

test('completing the dependency unblocks the dependant', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $anmeldung = Task::factory()->create([
        'key' => 't.anmeldung2',
        'title' => 'Anmeldung',
        'situation' => ['non_eu_employee'],
    ]);
    $bank = Task::factory()->create([
        'key' => 't.bank2',
        'title' => 'Open a bank account',
        'situation' => ['non_eu_employee'],
        'depends_on' => ['t.anmeldung2'],
    ]);

    UserTask::create([
        'user_id' => $user->id,
        'task_id' => $anmeldung->id,
        'status' => 'done',
        'completed_at' => now(),
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) use ($bank) {
        $cards = collect($page->toArray()['props']['tasks'])
            ->flatten(1)
            ->keyBy('task_id');

        expect($cards[$bank->id]['blocked'])->toBeFalse();

        return true;
    });
});

test('unpublished tasks never reach the page', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $hidden = Task::factory()->create([
        'key' => 't.hidden',
        'title' => 'Unverified guide',
        'situation' => ['non_eu_employee'],
        'is_published' => false,
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) use ($hidden) {
        $ids = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('task_id');

        expect($ids)->not->toContain($hidden->id);

        return true;
    });
});

test('eu_filter excludes non-matching tasks at materialisation', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'student', 'is_eu' => true]);

    Task::factory()->create([
        'key' => 't.permit',
        'title' => 'Residence permit',
        'situation' => ['student'],
        'eu_filter' => 'non_eu_only',
    ]);
    $enrol = Task::factory()->create([
        'key' => 't.enrol',
        'title' => 'Enrolment',
        'situation' => ['student'],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy'));

    $taskIds = $user->userTasks()->pluck('task_id');
    expect($taskIds)->toContain($enrol->id);
    expect(Task::where('key', 't.permit')->first()->userTasks()->where('user_id', $user->id)->exists())->toBeFalse();
});

// ── Report outdated ────────────────────────────────────────────────────

test('reporting a task outdated increments the counter once per user', function () {
    $user = User::factory()->onboarded()->create();
    $task = Task::factory()->create([
        'key' => 't.report',
        'verified_at' => now(),
        'situation' => ['non_eu_employee'],
    ]);

    $this->actingAs($user);
    $this->post(route('tasks.report-outdated', $task))->assertRedirect();
    $this->post(route('tasks.report-outdated', $task))->assertRedirect();

    expect($task->fresh()->outdated_reports)->toBe(1);
    expect(UserEvent::where('event_type', 'task_reported_outdated')->count())->toBe(1);
});

test('three reports clear the verified badge', function () {
    $task = Task::factory()->create([
        'key' => 't.report3',
        'verified_at' => now(),
        'situation' => ['non_eu_employee'],
    ]);

    foreach (range(1, 3) as $i) {
        $user = User::factory()->onboarded()->create();
        $this->actingAs($user);
        $this->post(route('tasks.report-outdated', $task));
    }

    $task->refresh();
    expect($task->outdated_reports)->toBe(3);
    expect($task->verified_at)->toBeNull();
});

// ── Import DAG guard ───────────────────────────────────────────────────

test('import aborts on a dependency cycle', function () {
    $dir = sys_get_temp_dir().'/bureaucracy_cycle_'.uniqid();
    mkdir($dir);
    $file = $dir.'/broken.yaml';
    file_put_contents($file, Yaml::dump([
        'situation' => 'non_eu_employee',
        'tasks' => [
            ['key' => 'a', 'title' => 'A', 'depends_on' => ['b']],
            ['key' => 'b', 'title' => 'B', 'depends_on' => ['a']],
        ],
    ]));

    $this->artisan('bureaucracy:import-tasks', ['file' => $file])
        ->expectsOutputToContain('dependency cycle')
        ->assertFailed();

    expect(Task::where('key', 'a')->exists())->toBeFalse();

    unlink($file);
    rmdir($dir);
});

test('import aborts on unknown dependency key', function () {
    $dir = sys_get_temp_dir().'/bureaucracy_unknown_'.uniqid();
    mkdir($dir);
    $file = $dir.'/broken.yaml';
    file_put_contents($file, Yaml::dump([
        'situation' => 'non_eu_employee',
        'tasks' => [
            ['key' => 'a', 'title' => 'A', 'depends_on' => ['ghost']],
        ],
    ]));

    $this->artisan('bureaucracy:import-tasks', ['file' => $file])
        ->expectsOutputToContain('unknown key')
        ->assertFailed();

    unlink($file);
    rmdir($dir);
});

test('import sets verified_at only from YAML', function () {
    $dir = sys_get_temp_dir().'/bureaucracy_verified_'.uniqid();
    mkdir($dir);
    $file = $dir.'/ok.yaml';
    file_put_contents($file, Yaml::dump([
        'situation' => 'non_eu_employee',
        'tasks' => [
            ['key' => 'v.checked', 'title' => 'Checked', 'verified_at' => '2026-06-10'],
            ['key' => 'v.unchecked', 'title' => 'Unchecked'],
        ],
    ]));

    $this->artisan('bureaucracy:import-tasks', ['file' => $file])->assertSuccessful();

    expect(Task::where('key', 'v.checked')->first()->verified_at?->toDateString())->toBe('2026-06-10');
    expect(Task::where('key', 'v.unchecked')->first()->verified_at)->toBeNull();

    unlink($file);
    rmdir($dir);
});
