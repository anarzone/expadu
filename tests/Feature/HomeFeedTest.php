<?php

uses()->group('slow');

use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ReviewedHomePlan;

test('tiles include urgent bureaucracy deadlines', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(12),
    ]);
    ReviewedHomePlan::activate($user, ['fixture.dashboard' => ['title' => 'Synthetic address preparation']],
        ['arrival_date' => now()->subDays(12)->toDateString()]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->loadDeferredProps(fn ($reload) => $reload
            ->where('tiles', function ($tiles) {
                return collect($tiles)->contains(
                    fn ($tile) => $tile['type'] === 'bureaucracy_deadline'
                        && $tile['title'] === 'Synthetic address preparation'
                );
            })
        )
    );
});

test('explicitly completed canonical work produces no deadline tile', function () {
    $user = User::factory()->onboarded()->create([
        'arrival_date' => now()->subDays(12),
    ]);
    $fixture = ReviewedHomePlan::activate($user, ['fixture.dashboard' => []],
        ['arrival_date' => now()->subDays(12)->toDateString()]);
    $process = app(ReconcileProcesses::class)->execute($user, $fixture['case']->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($user, $process, 'step_completed', ['step_id' => 'fixture.dashboard.complete'],
        $process->version, (string) Str::uuid());
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

test('one read-only page load assesses the bureaucracy plan once', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(12)]);
    ReviewedHomePlan::activate($user, ['fixture.dashboard' => []], ['arrival_date' => now()->subDays(12)->toDateString()]);
    $this->actingAs($user);

    DB::enableQueryLog();
    $this->get(route('dashboard'), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'dashboard', 'X-Inertia-Partial-Data' => 'tiles,bureaucracyBadge'])->assertSuccessful();
    $assessments = collect(DB::getQueryLog())
        ->filter(fn ($query) => str_contains($query['query'], 'bureaucracy_catalogue_pointers'))->count();
    DB::disableQueryLog();

    // Badge, tiles and feed share one assessment instead of recomputing it.
    expect($assessments)->toBe(1);
});
