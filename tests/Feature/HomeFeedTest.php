<?php

use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

test('home feed returns cards for onboarded user', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->has('cards')
        ->where('cards', fn ($cards) => count($cards) > 0)
    );
});

test('home feed includes settlement progress card', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(10),
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->has('cards', fn ($cards) => $cards
            ->each(fn ($card) => $card->hasAll(['type', 'data', 'priority']))
        )
    );
});

test('home feed includes blue highlight when user has urgent tasks', function () {
    $user = User::factory()->onboarded()->create();
    $task = Task::factory()->create(['urgency' => 'critical']);
    UserTask::factory()->create([
        'user_id' => $user->id,
        'task_id' => $task->id,
        'completed_at' => null,
    ]);

    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->where('cards', fn ($cards) => collect($cards)->contains(fn ($c) => $c['type'] === 'blue_highlight'))
    );
});

test('cards are sorted by priority descending', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->where('cards', function ($cards) {
            $priorities = collect($cards)->pluck('priority')->all();
            $sorted = collect($priorities)->sortDesc()->values()->all();

            return $priorities === $sorted;
        })
    );
});
