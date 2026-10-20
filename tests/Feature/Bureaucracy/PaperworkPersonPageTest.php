<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyAccessGrant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/** A spouse who shared their plan with the owner (view_plan only). */
function sharedSpouse(User $owner): array
{
    $spouse = User::factory()->onboarded()->create(['name' => 'Synthetic Spouse']);
    app(CaseFactStore::class)->synchronizeConfirmedFacts($spouse, ['citizenship_group' => 'non_eu'], 'onboarding');
    $person = app(EnsureAccountHolder::class)->person($spouse);
    $invite = app(ManageDelegation::class)->invite($owner, app(EnsureAccountHolder::class)->person($owner)->workspace, $spouse->email, ['view_plan']);
    app(ManageDelegation::class)->accept($spouse, $invite['token'], ['view_plan']);

    return [$spouse, $person];
}

test('the page lists only the people whose plans this account may see', function () {
    $owner = User::factory()->onboarded()->create();
    app(CaseFactStore::class)->synchronizeConfirmedFacts($owner, ['citizenship_group' => 'non_eu'], 'onboarding');
    [, $person] = sharedSpouse($owner);
    $stranger = app(EnsureAccountHolder::class)->person(User::factory()->onboarded()->create(['name' => 'Synthetic Stranger']));

    $this->actingAs($owner)->get('/bureaucracy')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('paperwork')
        ->has('people', 2)
        ->where('people.0.is_self', true)
        ->where('people.1.id', $person->id)
        ->where('people.1.is_self', false)
        ->where('people.1.label', 'Synthetic Spouse'));
    expect(json_encode($this->get('/bureaucracy')->viewData('page')))->not->toContain('Synthetic Stranger')
        ->and($stranger->exists)->toBeTrue();
});

test('a shared plan opens by deep link; anyone else’s does not exist for this account', function () {
    $owner = User::factory()->onboarded()->create();
    [$spouse, $person] = sharedSpouse($owner);

    $this->actingAs($owner)->get('/bureaucracy?person='.$person->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('entry.state', 'ready')->where('entry.plan.person_id', $person->id));

    // Someone who shared nothing: 404, never their plan.
    $stranger = app(EnsureAccountHolder::class)->person(User::factory()->onboarded()->create());
    $this->get('/bureaucracy?person='.$stranger->id)->assertNotFound();
    $this->get('/bureaucracy?person=999999')->assertNotFound();

    // The spouse can't open the owner's plan back: sharing goes one way.
    $ownerPerson = app(EnsureAccountHolder::class)->person($owner);
    $this->actingAs($spouse)->get('/bureaucracy?person='.$ownerPerson->id)->assertNotFound();

    // Once the grant is revoked, the deep link is gone too.
    BureaucracyAccessGrant::query()->where('person_id', $person->id)->update(['revoked_at' => now()]);
    $this->actingAs($owner)->get('/bureaucracy?person='.$person->id)->assertNotFound();
});

test('your own person id, or none, is your own plan', function () {
    $owner = User::factory()->onboarded()->create();
    app(CaseFactStore::class)->synchronizeConfirmedFacts($owner, ['citizenship_group' => 'non_eu'], 'onboarding');
    $self = app(EnsureAccountHolder::class)->person($owner);

    foreach (['/bureaucracy', '/bureaucracy?person='.$self->id, '/bureaucracy?person=nonsense'] as $url) {
        $this->actingAs($owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('entry.plan.person_id', $self->id));
    }
});
