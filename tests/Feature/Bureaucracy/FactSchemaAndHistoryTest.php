<?php

use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\BureaucracyCaseFact;
use App\Models\User;

test('the fact schema lists reviewed registry wording and answer shapes without values or legacy mappings', function () {
    $actor = User::factory()->onboarded()->create();
    $response = $this->actingAs($actor)->getJson('/bureaucracy/v2/facts/schema')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('schema_version', 'bureaucracy.fact-schema.1')
        ->assertJsonPath('registry_version', app(FactRegistry::class)->version());
    $visa = collect($response->json('facts'))->firstWhere('key', 'visa_expires_at');
    $title = collect($response->json('facts'))->firstWhere('key', 'current_residence_title');
    expect($response->json('facts'))->toHaveCount(app(FactRegistry::class)->all()->count())
        ->and($visa)->toMatchArray(['type' => 'date', 'date_semantics' => 'expiry', 'question' => app(FactRegistry::class)->definition('visa_expires_at')->question])
        ->and($title['options'])->toContain('national_d_visa')->and($title)->not->toHaveKeys(['legacy_values', 'permissible_sources', 'value']);
    auth()->logout();
    $this->getJson('/bureaucracy/v2/facts/schema')->assertUnauthorized();
});

test('the facts endpoint reports when each current answer was last checked', function () {
    $this->freezeTime();
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $url = '/bureaucracy/v2/people/'.$case->person_id.'/facts';
    $this->actingAs($actor)->putJson($url.'/german_level', ['value' => 'b1', 'expected_revision' => 1])->assertSuccessful();
    $this->getJson($url)->assertOk()->assertJsonPath('evidence.german_level.checked_at', now()->utc()->toIso8601String());
});

test('answer history shows assertions, corrections and changes with before and after values, newest first', function () {
    $this->freezeTime();
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $url = '/bureaucracy/v2/people/'.$case->person_id.'/facts';
    $this->actingAs($actor)->putJson($url.'/current_residence_title', ['value' => 'blue_card', 'effective_from' => '2024-01-01', 'expected_revision' => 1])->assertSuccessful();
    $first = $this->getJson($url)->json('evidence.current_residence_title.fact_id');
    $this->postJson($url.'/'.$first.'/corrections', ['value' => 'standard_work_permit', 'expected_revision' => 2])->assertSuccessful();
    $this->putJson($url.'/current_residence_title', ['value' => 'blue_card', 'effective_from' => '2025-06-01', 'expected_revision' => 3])->assertSuccessful();
    $before = BureaucracyCaseFact::query()->count();
    $history = $this->getJson($url.'/current_residence_title/history')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('schema_version', 'bureaucracy.fact-history.1')->assertJsonPath('key', 'current_residence_title')->json('entries');
    expect(array_column($history, 'operation'))->toBe(['changed', 'corrected', 'asserted'])
        ->and($history[0]['before'])->toMatchArray(['value' => 'standard_work_permit'])->and($history[0]['after'])->toBe(['answer_state' => 'value', 'value' => 'blue_card'])
        ->and($history[0]['effective_from'])->toBe('2025-06-01')
        ->and($history[1]['before'])->toBe(['fact_id' => $first, 'answer_state' => 'value', 'value' => 'blue_card'])
        ->and($history[1]['after']['value'])->toBe('standard_work_permit')->and($history[2]['before'])->toBeNull()
        ->and($history[2]['state'])->toBe('superseded');
    expect(BureaucracyCaseFact::query()->count())->toBe($before);
    $this->getJson($url.'/invented_legal_status/history')->assertNotFound();
});

test('answer history needs fact-reading access to the person', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($helper);
    $two = app(EnsureAccountHolder::class)->dossier($subject);
    $invite = app(ManageDelegation::class)->invite($helper, $one->person->workspace, $subject->email, ['view_plan']);
    app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_plan']);
    $this->actingAs($helper)->getJson('/bureaucracy/v2/people/'.$two->person_id.'/facts/german_level/history')->assertForbidden();
});
