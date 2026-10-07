<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCaseQuestion;
use App\Models\User;
use Illuminate\Support\Str;

test('question sessions work through HTTP with read-only preview and no hidden interview requirement', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $personUrl = '/bureaucracy/v2/people/'.$case->person_id;
    $this->actingAs($actor)->getJson($personUrl.'/question-preview?jurisdiction=de-nrw-cologne')->assertSuccessful()->assertJsonPath('question.fact_key', 'arrival_planned');
    expect(BureaucracyCaseQuestion::query()->count())->toBe(0);
    $id = $this->postJson($personUrl.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])->assertCreated()->json('session_id');
    $url = '/bureaucracy/v2/question-sessions/'.$id;
    $offer = $this->postJson($url.'/next', ['request_id' => (string) Str::uuid()])->assertSuccessful()->assertJsonPath('status', 'offered')->json('question');
    $this->postJson($url.'/answers/'.$offer['id'], ['token' => $offer['token'], 'value' => true])->assertSuccessful()->assertJsonPath('fact_revision', 2);
    $this->getJson($personUrl.'/question-preview?jurisdiction=de-nrw-cologne')->assertSuccessful()->assertJsonPath('question.fact_key', 'citizenship_group');
});

test('revoking helper access invalidates an already offered question at submission', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($helper);
    $two = app(EnsureAccountHolder::class)->dossier($subject);
    $invite = app(ManageDelegation::class)->invite($helper, $one->person->workspace, $subject->email, ['view_plan', 'edit_facts']);
    app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_plan', 'edit_facts']);
    $id = $this->actingAs($helper)->postJson('/bureaucracy/v2/people/'.$two->person_id.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])->assertCreated()->json('session_id');
    $url = '/bureaucracy/v2/question-sessions/'.$id;
    $offer = $this->postJson($url.'/next', ['request_id' => (string) Str::uuid()])->assertSuccessful()->json('question');
    $grant = BureaucracyAccessGrant::query()->where('person_id', $two->person_id)->sole();
    app(ManageDelegation::class)->revoke($subject, $grant);
    $this->postJson($url.'/answers/'.$offer['id'], ['token' => $offer['token'], 'value' => 'non_eu'])->assertForbidden();
    expect($two->facts()->count())->toBe(0);
});

test('foreign question IDs, expired tokens and private HTML form failures are handled safely', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $id = $this->actingAs($actor)->postJson('/bureaucracy/v2/people/'.$case->person_id.'/question-sessions', ['request_id' => (string) Str::uuid(), 'jurisdiction' => 'de-nrw-cologne'])->assertCreated()->json('session_id');
    $url = '/bureaucracy/v2/question-sessions/'.$id;
    $offer = $this->postJson($url.'/next', ['request_id' => (string) Str::uuid()])->assertSuccessful()->json('question');
    $this->postJson($url.'/answers/'.($offer['id'] + 100000), ['token' => $offer['token'], 'value' => 'eu'])->assertForbidden();
    $this->post($url.'/answers/'.$offer['id'], ['token' => $offer['token'], 'value' => 'private-invalid-answer'], ['Accept' => 'text/html'])->assertUnprocessable()->assertSessionMissing('_old_input');
    $this->travel(25)->hours();
    $this->postJson($url.'/answers/'.$offer['id'], ['token' => $offer['token'], 'value' => 'eu'])->assertConflict();
    expect($case->facts()->count())->toBe(0);
});
