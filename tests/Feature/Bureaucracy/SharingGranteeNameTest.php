<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\User;

/** The helper invites the subject; the subject accepts, sharing their own record with the helper. */
function shareWithHelper($test, User $helper, User $subject): int
{
    $self = app(EnsureAccountHolder::class)->person($helper);
    $token = $test->actingAs($helper)->postJson('/bureaucracy/v2/invitations', [
        'workspace_id' => $self->workspace_id, 'email' => $subject->email, 'scopes' => ['view_plan'],
    ])->assertCreated()->json('token');

    return $test->actingAs($subject)->postJson('/bureaucracy/v2/invitations/accept', [
        'token' => $token, 'scopes' => ['view_plan'], 'accept' => true,
        'notice_version' => config('bureaucracy_family.sharing_notice_version'),
    ])->assertSuccessful()->json('person_id');
}

test('🔒 the record\'s manager sees whom they share with by name, never by email', function () {
    $helper = User::factory()->onboarded()->create(['name' => 'Maria Example', 'email' => 'maria.private@example.test']);
    $subject = User::factory()->onboarded()->create();
    $personId = shareWithHelper($this, $helper, $subject);

    $this->actingAs($subject)->getJson('/bureaucracy/v2/people/'.$personId.'/sharing')->assertSuccessful()
        ->assertJsonPath('grants.0.name', 'Maria Example')->assertJsonPath('grants.0.is_my_access', false)
        ->assertDontSee('maria.private')->assertDontSee('example.test');
});

test('🔒 someone with access sees only their own grant, without anyone\'s name', function () {
    $helper = User::factory()->onboarded()->create(['name' => 'Maria Example']);
    $subject = User::factory()->onboarded()->create(['name' => 'Sam Subject']);
    $personId = shareWithHelper($this, $helper, $subject);

    $this->actingAs($helper)->getJson('/bureaucracy/v2/people/'.$personId.'/sharing')->assertSuccessful()
        ->assertJsonCount(1, 'grants')->assertJsonPath('grants.0.is_my_access', true)
        ->assertJsonPath('grants.0.name', null)->assertDontSee('Sam Subject');

    // Anyone else learns nothing, not even that the record exists.
    $this->actingAs(User::factory()->onboarded()->create())->getJson('/bureaucracy/v2/people/'.$personId.'/sharing')->assertNotFound();
});
