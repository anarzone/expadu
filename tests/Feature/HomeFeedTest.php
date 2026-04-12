<?php

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

test('home feed returns unified feed for onboarded user', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->missing('feed')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('feed')
            ->has('feed.recommendations')
            ->has('feed.settlement')
            ->has('feed.places')
            ->has('weather')
        )
    );
});

test('home feed includes settlement progress', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(10),
    ]);
    $task = Task::factory()->create(['urgency' => 'critical', 'situation' => ['non_eu_employee']]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $task->id]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->missing('feed')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('feed.settlement')
            ->where('feed.settlement.total', fn ($v) => $v > 0)
        )
    );
});

test('home feed recommendations sorted by priority', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->missing('feed')
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('feed.recommendations', function ($recs) {
                if (count($recs) < 2) {
                    return true;
                }
                $priorities = collect($recs)->pluck('priority')->all();
                $sorted = collect($priorities)->sortDesc()->values()->all();

                return $priorities === $sorted;
            })
        )
    );
});
