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

test('import fails closed when a catalogue file is malformed', function () {
    $dir = sys_get_temp_dir().'/bureaucracy_malformed_'.uniqid();
    mkdir($dir);
    $file = $dir.'/broken.yaml';
    file_put_contents($file, "situation: [unterminated\n");

    $this->artisan('bureaucracy:import-tasks', ['file' => $file])
        ->expectsOutputToContain('YAML parse error')
        ->assertFailed();

    unlink($file);
    rmdir($dir);
});

test('import aborts before writing when a figure placeholder is unknown', function () {
    $dir = sys_get_temp_dir().'/bureaucracy_figure_'.uniqid();
    mkdir($dir);
    $file = $dir.'/broken.yaml';
    file_put_contents($file, Yaml::dump([
        'situation' => 'core',
        'tasks' => [
            ['key' => 'figure.valid', 'title' => 'Valid task'],
            ['key' => 'figure.invalid', 'title' => 'Pay {{figure:not_configured}}'],
        ],
    ]));

    $this->artisan('bureaucracy:import-tasks', ['file' => $file])
        ->expectsOutputToContain('unknown figure')
        ->assertFailed();

    expect(Task::whereIn('key', ['figure.valid', 'figure.invalid'])->exists())->toBeFalse();

    unlink($file);
    rmdir($dir);
});

test('import aborts before writing when a task title is missing', function () {
    $dir = sys_get_temp_dir().'/bureaucracy_title_'.uniqid();
    mkdir($dir);
    $file = $dir.'/broken.yaml';
    file_put_contents($file, Yaml::dump([
        'situation' => 'core',
        'tasks' => [
            ['key' => 'title.valid', 'title' => 'Valid task'],
            ['key' => 'title.missing'],
        ],
    ]));

    $this->artisan('bureaucracy:import-tasks', ['file' => $file])
        ->expectsOutputToContain('missing `title`')
        ->assertFailed();

    expect(Task::whereIn('key', ['title.valid', 'title.missing'])->exists())->toBeFalse();

    unlink($file);
    rmdir($dir);
});

// ── Path refinement ────────────────────────────────────────────────────

test('refining the path swaps in the sub-path tasks and hides untouched old-path ones', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $shared = Task::factory()->create([
        'key' => 'p.anmeldung',
        'title' => 'Anmeldung',
        'situation' => ['non_eu_employee', 'non_eu_employee_blue_card'],
        'applies_if' => [
            ['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'standard'],
            ['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'blue_card'],
        ],
    ]);
    $standardOnly = Task::factory()->create([
        'key' => 'p.standard-permit',
        'title' => 'Standard permit',
        'situation' => ['non_eu_employee'],
        'applies_if' => [['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'standard']],
    ]);
    $blueCardOnly = Task::factory()->create([
        'key' => 'p.blue-card',
        'title' => 'Blue Card application',
        'situation' => ['non_eu_employee_blue_card'],
        'applies_if' => [['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'blue_card']],
    ]);

    $this->actingAs($user);
    $this->get(route('bureaucracy')); // materialises base-branch tasks

    expect($user->userTasks()->pluck('task_id'))->toContain($standardOnly->id);

    $this->post(route('bureaucracy.set-path'), ['path' => 'non_eu_employee_blue_card'])
        ->assertRedirect();
    $response = $this->get(route('bureaucracy')); // recompute for the refined path

    expect($user->fresh()->bureaucracy_path)->toBe('non_eu_employee_blue_card');
    // The row is preserved (nothing a user has is deleted) — but the
    // untouched old-path task is off the page entirely.
    expect($user->userTasks()->pluck('task_id'))->toContain($standardOnly->id);

    $response->assertInertia(function ($page) use ($shared, $blueCardOnly, $standardOnly) {
        $ids = collect($page->toArray()['props']['tasks'])->flatten(1)->pluck('task_id');

        expect($ids)->toContain($shared->id);
        expect($ids)->toContain($blueCardOnly->id);
        expect($ids)->not->toContain($standardOnly->id);

        return true;
    });
});

test('a touched old-path task survives a path switch in the no-longer-relevant lane', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $standardOnly = Task::factory()->create([
        'key' => 'p.touched-standard',
        'title' => 'Standard permit',
        'situation' => ['non_eu_employee'],
        'applies_if' => [['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'standard']],
        'documents_required' => ['Passport'],
    ]);
    UserTask::create([
        'user_id' => $user->id,
        'task_id' => $standardOnly->id,
        'documents_checked' => ['Passport'], // the user ticked a document
    ]);

    $this->actingAs($user);
    $this->post(route('bureaucracy.set-path'), ['path' => 'non_eu_employee_blue_card']);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) use ($standardOnly) {
        $ghosts = collect($page->toArray()['props']['tasks']['no_longer_relevant'])->pluck('task_id');

        expect($ghosts)->toContain($standardOnly->id);

        return true;
    });
});

test('refining the path keeps tasks with progress as history', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $doneTask = Task::factory()->create([
        'key' => 'p.done',
        'title' => 'Already done',
        'situation' => ['non_eu_employee'],
    ]);
    UserTask::create([
        'user_id' => $user->id,
        'task_id' => $doneTask->id,
        'status' => 'done',
        'completed_at' => now(),
    ]);

    $this->actingAs($user);
    $this->post(route('bureaucracy.set-path'), ['path' => 'non_eu_employee_blue_card']);

    expect($user->userTasks()->pluck('task_id'))->toContain($doneTask->id);
});

test('path refinement rejects values outside the user branch', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    $this->actingAs($user);
    $this->post(route('bureaucracy.set-path'), ['path' => 'freelancer_gewerbe'])
        ->assertSessionHasErrors('path');

    $euUser = User::factory()->onboarded()->create(['situation' => 'eu_employee']);
    $this->actingAs($euUser);
    $this->post(route('bureaucracy.set-path'), ['path' => 'non_eu_employee_blue_card'])
        ->assertNotFound();
});

// ── Document checklists ────────────────────────────────────────────────

test('checked documents persist and unknown entries are dropped', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);
    $task = Task::factory()->create([
        'key' => 'd.docs',
        'situation' => ['non_eu_employee'],
        'documents_required' => [
            'Passport',
            ['label' => 'Rental contract', 'note' => 'Original required'],
        ],
    ]);
    $userTask = UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);

    $this->actingAs($user);
    $this->patch(route('user-tasks.update', $userTask), [
        'documents_checked' => ['Passport', 'Rental contract', 'Forged entry'],
    ])->assertRedirect();

    expect($userTask->fresh()->documents_checked)->toBe(['Passport', 'Rental contract']);
});

test('document checks are scoped to the owning user', function () {
    $owner = User::factory()->onboarded()->create();
    $task = Task::factory()->create(['key' => 'd.scope', 'situation' => ['core']]);
    $userTask = UserTask::create(['user_id' => $owner->id, 'task_id' => $task->id]);

    $this->actingAs(User::factory()->onboarded()->create());
    $this->patch(route('user-tasks.update', $userTask), [
        'documents_checked' => [],
    ])->assertForbidden();
});

// ── Info cards ─────────────────────────────────────────────────────────

test('info cards land in their own bucket and stay out of progress', function () {
    $user = User::factory()->onboarded()->create(['situation' => 'non_eu_employee']);

    Task::factory()->create([
        'key' => 'i.task',
        'title' => 'Actionable',
        'situation' => ['non_eu_employee'],
    ]);
    $info = Task::factory()->create([
        'key' => 'i.church-tax',
        'title' => 'Church tax',
        'type' => 'info',
        'situation' => ['non_eu_employee'],
    ]);

    $this->actingAs($user);
    $response = $this->get(route('bureaucracy'));

    $response->assertInertia(function ($page) use ($info) {
        $props = $page->toArray()['props'];

        $infoIds = collect($props['tasks']['info'])->pluck('task_id');
        expect($infoIds)->toContain($info->id);
        expect($props['progress']['total'])->toBe(1);

        return true;
    });
});

test('importer reads the type field and defaults to task', function () {
    $dir = sys_get_temp_dir().'/bureaucracy_type_'.uniqid();
    mkdir($dir);
    $file = $dir.'/typed.yaml';
    file_put_contents($file, Yaml::dump([
        'situation' => 'core',
        'tasks' => [
            ['key' => 'ty.info', 'title' => 'Good to know', 'type' => 'info'],
            ['key' => 'ty.plain', 'title' => 'Plain task'],
        ],
    ]));

    $this->artisan('bureaucracy:import-tasks', ['file' => $file])->assertSuccessful();

    expect(Task::where('key', 'ty.info')->first()->type)->toBe('info');
    expect(Task::where('key', 'ty.plain')->first()->type)->toBe('task');

    unlink($file);
    rmdir($dir);
});

test('prune removes everything outside the catalogue — moved keys AND keyless leftovers', function () {
    // A task whose key left the catalogue, and a keyless legacy/ad-hoc row:
    // YAML is the single source of truth, both must go.
    $stale = Task::factory()->create(['key' => 'zz.gone', 'situation' => ['core']]);
    $keyless = Task::factory()->create(['key' => null, 'situation' => ['core']]);

    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();

    expect(Task::whereKey($stale->id)->exists())->toBeFalse();
    expect(Task::whereKey($keyless->id)->exists())->toBeFalse();
    // The real catalogue survives the prune.
    expect(Task::where('key', 'core.anmeldung')->exists())->toBeTrue();
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
