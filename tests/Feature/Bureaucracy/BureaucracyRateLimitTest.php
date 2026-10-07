<?php

use App\Models\User;

test('the hourly dependent request cap holds after the group minute window resets', function () {
    $actor = User::factory()->onboarded()->create();
    $this->actingAs($actor);

    foreach (range(1, 5) as $_) {
        $this->postJson('/bureaucracy/v2/dependents', ['label' => 'Synthetic child'])->assertStatus(202);
    }
    $this->travel(61)->seconds();

    $this->postJson('/bureaucracy/v2/dependents', ['label' => 'Synthetic child'])->assertTooManyRequests();
    $this->getJson('/bureaucracy/v2/people')->assertSuccessful();
});

test('the hourly invitation cap holds after the group minute window resets', function () {
    $actor = User::factory()->onboarded()->create();
    $workspaceId = $this->actingAs($actor)->postJson('/bureaucracy/v2/people/self')->assertSuccessful()->json('person.workspace_id');
    $invite = fn () => $this->postJson('/bureaucracy/v2/invitations', [
        'workspace_id' => $workspaceId, 'email' => 'synthetic-recipient@example.test', 'scopes' => ['view_plan'],
    ]);

    foreach (range(1, 10) as $_) {
        $invite()->assertCreated();
    }
    $this->travel(61)->seconds();

    $invite()->assertTooManyRequests();
});
