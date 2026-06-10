<?php

uses()->group('slow');

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

test('tiles include urgent bureaucracy deadlines', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(12),
    ]);
    $task = Task::factory()->create([
        'title' => 'Register your address (Anmeldung)',
        'urgency' => 'critical',
        'situation' => [$user->situation->value],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 14,
    ]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $task->id, 'is_applicable' => true]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('tiles', function ($tiles) {
                return collect($tiles)->contains(
                    fn ($tile) => $tile['type'] === 'bureaucracy_deadline'
                        && str_contains($tile['title'], 'Anmeldung')
                );
            })
        )
    );
});

test('completed tasks produce no deadline tile', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(12),
    ]);
    $task = Task::factory()->create([
        'urgency' => 'critical',
        'situation' => [$user->situation->value],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 14,
    ]);
    UserTask::create([
        'user_id' => $user->id,
        'task_id' => $task->id,
        'is_applicable' => true,
        'status' => 'done',
        'completed_at' => now(),
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('tiles', function ($tiles) {
                return ! collect($tiles)->contains(fn ($tile) => $tile['type'] === 'bureaucracy_deadline');
            })
        )
    );
});

test('tiles are sorted by score descending', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('tiles', function ($tiles) {
                if (count($tiles) < 2) {
                    return true;
                }
                $scores = collect($tiles)->pluck('score')->all();

                return $scores === collect($scores)->sortDesc()->values()->all();
            })
        )
    );
});
