<?php

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('renders Paperwork from the v2 plan of the signed-in account', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $this->artisan('bureaucracy:compile-catalogue --deploy')->assertSuccessful();
    $user = User::factory()->onboarded()->create(['city' => 'Köln']);
    $case = app(EnsureAccountHolder::class)->dossier($user);
    app(RecordFactChange::class)->execute($user, $case->person, 'arrival_planned', false, null, 1);
    app(RecordFactChange::class)->execute($user, $case->person->fresh(), 'registration_status', 'not_registered', null, 2);

    $this->actingAs($user)->get('/bureaucracy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('paperwork')
        ->where('entry.state', 'ready')
        ->where('jurisdiction', 'de-nrw-cologne')
        ->has('entry.plan.processes')
        ->has('entry.plan.processes.0.progress_options')
        ->has('entry.plan.processes.0.untrackable'));
});

it('asks a person without a record to set up first', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->get('/bureaucracy')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('paperwork')->where('entry.state', 'setup_required')->where('entry.plan', null));
});

it('keeps the retired page reachable for its engine while it is phased out', function () {
    $this->actingAs(User::factory()->onboarded()->create())->get('/bureaucracy/legacy')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('bureaucracy'));
});
