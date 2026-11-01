<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Models\Task;
use App\Models\User;
use Tests\Support\PaperworkStart;

/**
 * Address registration (Anmeldung) in the plan, from the answers a person has
 * given and the ones they haven't yet. The imported clock is not promoted to a
 * reviewed legal deadline.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all()));
    $store->activate($release->id, null);
});

function anmeldungAction(array $plan): ?array
{
    return collect($plan['actions'])->firstWhere('source_rule_id', 'core.anmeldung');
}

/** A non-EU employee in Cologne who arrived ten days ago, plus these answers. */
function startedWith(array $answers = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    PaperworkStart::answer($user, [
        'citizenship_group' => 'non_eu',
        'purpose' => 'employment',
        'arrival_planned' => false,
        'arrival_date' => now()->subDays(10)->toDateString(),
        ...$answers,
    ]);

    return $user->fresh();
}

it('tells the user the Anmeldung clock is missing instead of showing nothing', function () {
    $user = startedWith(['registration_status' => 'not_registered']);

    $plan = $this->actingAs($user)->getJson('/paperwork/v2/plan')->assertSuccessful()->json('plan');
    $anmeldung = anmeldungAction($plan);
    expect($anmeldung)->not->toBeNull()
        ->and($anmeldung['dates'][0]['state'])->toBe('date_unknown')
        ->and($anmeldung['dates'][0]['date'])->toBeNull()
        ->and($plan['overview']['question']['fact_key'])->toBe('moved_in_at');
});

it('anchors the imported preparation target to the supplied move-in date', function () {
    $user = startedWith([
        'moved_in_at' => now()->subDays(3)->toDateString(),
        'registration_status' => 'not_registered',
    ]);

    expect(app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString())['values']['moved_in_at'] ?? null)
        ->toBe(now()->subDays(3)->toDateString());

    $plan = $this->actingAs($user)->getJson('/paperwork/v2/plan')->assertSuccessful()->json('plan');
    $anmeldung = anmeldungAction($plan);
    expect($anmeldung['dates'][0]['state'])->toBe('dated')
        ->and($anmeldung['dates'][0]['date'])->toBe(now()->addDays(11)->toDateString())
        ->and($anmeldung['dates'][0]['kind'])->toBe('preparation_target');
});

it('keeps undated registration preparation reachable alongside its question', function () {
    $user = startedWith(['registration_status' => 'not_registered']);

    $plan = $this->actingAs($user)->getJson('/paperwork/v2/plan')->assertSuccessful()->json('plan');
    $action = anmeldungAction($plan);
    expect($action)->not->toBeNull()
        ->and($action['id'])->toBeIn($plan['progress']['todo']['ids'])
        ->and($plan['overview']['question']['fact_key'])->toBe('moved_in_at');
});

it('asks about actual registration before inventing a need to register again', function () {
    $user = startedWith(['moved_in_at' => now()->subDays(3)->toDateString()]);
    $plan = $this->actingAs($user)->getJson('/paperwork/v2/plan')->assertSuccessful()->json('plan');
    $guidance = collect($plan['guidance'])->firstWhere('id', 'core.anmeldung');
    expect(anmeldungAction($plan))->toBeNull()
        ->and($guidance['missing_facts'])->toBe(['registration_status']);
});
