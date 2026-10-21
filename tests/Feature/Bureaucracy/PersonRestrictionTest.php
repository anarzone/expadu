<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcessingConsent;
use App\Models\User;
use Illuminate\Support\Str;

/** A record holder with their Paperwork record, ready to stop and resume. */
function recordHolder(): array
{
    $holder = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($holder);
    app(EnsureAccountHolder::class)->dossier($holder);

    return [$holder, $person];
}

/** Someone the holder shared their plan with, with every scope a grant can carry. */
function sharedWith(User $holder, BureaucracyPerson $person): User
{
    $helper = User::factory()->onboarded()->create();
    BureaucracyAccessGrant::query()->create([
        'person_id' => $person->id, 'grantee_user_id' => $helper->id, 'grantor_user_id' => $holder->id,
        'authority_basis' => 'adult_acceptance', 'notice_version' => (string) config('bureaucracy_family.sharing_notice_version'),
        'scopes' => ['view_plan', 'view_facts', 'edit_facts', 'manage_process', 'manage_evidence', 'request_ai'],
        'accepted_at' => now()->subDay(), 'expires_at' => now()->addYear(),
    ]);

    return $helper;
}

test('🔒 the record holder stops and resumes processing, and the stop stays until they resume', function () {
    [$holder, $person] = recordHolder();
    $url = "/bureaucracy/v2/people/{$person->id}/restriction";

    $this->actingAs($holder)->getJson($url)->assertOk()->assertExactJson(['stopped' => false]);
    $this->postJson($url)->assertOk()->assertExactJson(['stopped' => true]);

    expect($person->fresh()->processing_stopped_at)->not->toBeNull()
        ->and($person->fresh()->record_status)->toBe('active');
    $this->getJson($url)->assertOk()->assertExactJson(['stopped' => true]);
    $this->postJson($url)->assertOk();

    $this->deleteJson($url)->assertOk()->assertExactJson(['stopped' => false]);
    expect($person->fresh()->processing_stopped_at)->toBeNull();
});

test('🔒 only the record holder can stop, resume or see it: not someone it is shared with, nor anyone else', function () {
    [$holder, $person] = recordHolder();
    $helper = sharedWith($holder, $person);
    $stranger = User::factory()->onboarded()->create();
    $url = "/bureaucracy/v2/people/{$person->id}/restriction";

    foreach ([$helper, $stranger] as $other) {
        $this->actingAs($other)->getJson($url)->assertForbidden();
        $this->postJson($url)->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
    }
    expect($person->fresh()->processing_stopped_at)->toBeNull();

    $this->actingAs($holder)->postJson($url)->assertOk();
    $this->actingAs($helper)->deleteJson($url)->assertForbidden();
    expect($person->fresh()->processing_stopped_at)->not->toBeNull();

    auth()->logout();
    $this->postJson($url)->assertUnauthorized();
});

test('🔒 stopping withdraws the record\'s AI processing', function () {
    [$holder, $person] = recordHolder();
    $case = BureaucracyCase::query()->where('person_id', $person->id)->where('status', 'active')->sole();
    $consent = BureaucracyProcessingConsent::query()->create([
        'id' => (string) Str::uuid(), 'actor_id' => $holder->id, 'case_id' => $case->id, 'request_key' => (string) Str::uuid(),
        'purpose' => 'fact_extraction', 'provider_version' => 'test', 'notice_version' => 'test', 'input_digest' => str_repeat('a', 64),
        'state' => 'granted', 'result' => 'private', 'granted_at' => now(), 'expires_at' => now()->addHour(), 'delete_after' => now()->addDay(),
    ]);

    $this->actingAs($holder)->postJson("/bureaucracy/v2/people/{$person->id}/restriction")->assertOk();

    expect($consent->fresh()->state)->toBe('withdrawn')
        ->and($consent->fresh()->result)->toBeNull()
        ->and($consent->fresh()->withdrawn_at)->not->toBeNull();
});
