<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyInvitation;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyWorkspace;
use App\Models\User;

test('family reads do not create a person or require onboarding', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor)->getJson('/bureaucracy/v2/people')->assertSuccessful()->assertExactJson(['people' => [], 'next_cursor' => null]);
    expect(BureaucracyWorkspace::query()->count())->toBe(0)->and(BureaucracyPerson::query()->count())->toBe(0);
    $this->postJson('/bureaucracy/v2/people/self')->assertSuccessful()->assertJsonPath('person.is_self', true);
    $this->postJson('/bureaucracy/v2/people/self')->assertSuccessful();
    expect(BureaucracyPerson::query()->count())->toBe(1);
});

test('the family API previews only the intended request and requires explicit current acceptance', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $self = app(EnsureAccountHolder::class)->person($helper);
    $response = $this->actingAs($helper)->postJson('/bureaucracy/v2/invitations', [
        'workspace_id' => $self->workspace_id, 'email' => $subject->email, 'scopes' => ['view_plan', 'view_facts'],
    ])->assertCreated();
    $token = $response->json('token');
    expect($token)->toBeString()->toHaveLength(64);
    $this->actingAs($subject)->postJson('/bureaucracy/v2/invitations/inspect', ['token' => $token])
        ->assertSuccessful()->assertJsonPath('invitation.requested_scopes', ['view_plan', 'view_facts'])
        ->assertJsonMissingPath('invitation.facts')->assertJsonMissingPath('invitation.recipient_hash');
    $payload = ['token' => $token, 'scopes' => ['view_plan'], 'notice_version' => config('bureaucracy_family.sharing_notice_version')];
    $this->postJson('/bureaucracy/v2/invitations/accept', $payload)->assertUnprocessable();
    $this->postJson('/bureaucracy/v2/invitations/accept', [...$payload, 'accept' => true, 'notice_version' => 'old'])->assertUnprocessable();
    $personId = $this->postJson('/bureaucracy/v2/invitations/accept', [...$payload, 'accept' => true])
        ->assertSuccessful()->json('person_id');
    $this->actingAs($helper)->getJson('/bureaucracy/v2/people/'.$personId)->assertSuccessful()
        ->assertJsonPath('person.scopes', ['view_plan'])->assertJsonMissingPath('person.facts');
    $grantId = $this->actingAs($subject)->getJson('/bureaucracy/v2/people/'.$personId.'/sharing')
        ->assertSuccessful()->assertJsonCount(1, 'grants')->json('grants.0.id');
    $this->actingAs($subject)->deleteJson('/bureaucracy/v2/grants/'.$grantId)->assertNoContent();
    $this->actingAs($helper)->getJson('/bureaucracy/v2/people/'.$personId)->assertNotFound();
});

test('invalid HTML family requests do not flash private invitation input into the session', function () {
    $actor = User::factory()->onboarded()->create();
    $response = $this->actingAs($actor)->post('/bureaucracy/v2/invitations', [
        'email' => 'private-family@example.test', 'label' => 'Private child', 'token' => 'private-token',
    ], ['Accept' => 'text/html']);
    $response->assertUnprocessable()->assertSessionMissing('_old_input');
    expect(BureaucracyInvitation::query()->count())->toBe(0);
});

test('family data export and erasure require recent authentication as well as subject ownership', function () {
    $actor = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($actor);
    app(EnsureAccountHolder::class)->dossier($actor);
    $this->actingAs($actor)->postJson('/bureaucracy/v2/people/'.$person->id.'/export')->assertStatus(423);
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/bureaucracy/v2/people/'.$person->id.'/export')->assertSuccessful()->assertJsonPath('person.id', $person->id);
    $this->deleteJson('/bureaucracy/v2/people/'.$person->id, ['confirm' => false])->assertUnprocessable();
    $this->deleteJson('/bureaucracy/v2/people/'.$person->id, ['confirm' => true])->assertNoContent();
    $this->getJson('/bureaucracy/v2/people/'.$person->id)->assertNotFound();
});
