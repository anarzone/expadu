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
    $url = "/bureaucracy/v2/people/{$person->id}/processing-restriction";

    $stoppedAt = $this->actingAs($holder)->putJson($url, ['stopped' => true])->assertOk()->json('processing_stopped_at');

    expect($stoppedAt)->toBeString()
        ->and($person->fresh()->processing_stopped_at?->toIso8601String())->toBe($stoppedAt)
        ->and($person->fresh()->record_status)->toBe('active');
    // Stopping again keeps the first stop.
    $this->putJson($url, ['stopped' => true])->assertOk()->assertJsonPath('processing_stopped_at', $stoppedAt);

    $this->putJson($url, ['stopped' => false])->assertOk()->assertExactJson(['processing_stopped_at' => null]);
    expect($person->fresh()->processing_stopped_at)->toBeNull();
    $this->putJson($url, [])->assertUnprocessable();
});

test('🔒 only the record holder can stop or resume: not someone it is shared with, nor anyone else', function () {
    [$holder, $person] = recordHolder();
    $helper = sharedWith($holder, $person);
    $stranger = User::factory()->onboarded()->create();
    $url = "/bureaucracy/v2/people/{$person->id}/processing-restriction";

    foreach ([$helper, $stranger] as $other) {
        $this->actingAs($other)->putJson($url, ['stopped' => true])->assertForbidden();
    }
    expect($person->fresh()->processing_stopped_at)->toBeNull();

    $this->actingAs($holder)->putJson($url, ['stopped' => true])->assertOk();
    $this->actingAs($helper)->putJson($url, ['stopped' => false])->assertForbidden();
    expect($person->fresh()->processing_stopped_at)->not->toBeNull();

    auth()->logout();
    $this->putJson($url, ['stopped' => false])->assertUnauthorized();
});

test('🔒 stopping withdraws the record\'s AI processing', function () {
    [$holder, $person] = recordHolder();
    $case = BureaucracyCase::query()->where('person_id', $person->id)->where('status', 'active')->sole();
    $consent = BureaucracyProcessingConsent::query()->create([
        'id' => (string) Str::uuid(), 'actor_id' => $holder->id, 'case_id' => $case->id, 'request_key' => (string) Str::uuid(),
        'purpose' => 'fact_extraction', 'provider_version' => 'test', 'notice_version' => 'test', 'input_digest' => str_repeat('a', 64),
        'state' => 'granted', 'result' => 'private', 'granted_at' => now(), 'expires_at' => now()->addHour(), 'delete_after' => now()->addDay(),
    ]);

    $this->actingAs($holder)->putJson("/bureaucracy/v2/people/{$person->id}/processing-restriction", ['stopped' => true])->assertOk();

    expect($consent->fresh()->state)->toBe('withdrawn')
        ->and($consent->fresh()->result)->toBeNull()
        ->and($consent->fresh()->withdrawn_at)->not->toBeNull();
});
