<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonAccess;
use App\Models\User;
use Illuminate\Support\Str;

/* Paperwork on the iPhone: the web's own v2 routes and rules, with the app's token. */

test('🔒 Paperwork needs a token and a confirmed address', function () {
    $this->getJson('/api/v1/paperwork/v2/plan')->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)->getJson('/api/v1/paperwork/v2/plan')->assertForbidden();
});

test('the app reads the same plan entry the web does', function () {
    $user = User::factory()->onboarded()->create();
    app(EnsureAccountHolder::class)->dossier($user);
    $token = $user->createToken('iPhone')->plainTextToken;

    $app = $this->withToken($token)->getJson('/api/v1/paperwork/v2/plan')->assertOk()->json();
    app('auth')->forgetGuards();
    $web = $this->actingAs($user)->getJson('/paperwork/v2/plan')->assertOk()->json();

    expect($app['schema_version'])->toBe('bureaucracy.account-entry.1')
        ->and($app['state'])->toBe($web['state'])
        ->and(array_keys($app['plan'] ?? []))->toBe(array_keys($web['plan'] ?? []));
});

test('🔒 while stopped, the app can resume but change nothing else', function () {
    $user = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($user);
    app(EnsureAccountHolder::class)->dossier($user);
    $token = $user->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->putJson("/api/v1/paperwork/v2/people/{$person->id}/processing-restriction", ['stopped' => true])->assertOk();
    $this->withToken($token)->getJson('/api/v1/paperwork/v2/plan')->assertOk()->assertJsonPath('state', 'processing_stopped')->assertJsonPath('plan', null);
    $this->withToken($token)->putJson("/api/v1/paperwork/v2/people/{$person->id}/evidence/".Str::uuid(), [
        'request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'details' => ['label' => 'Passport', 'kind' => 'document', 'reported_available' => true, 'requirement_refs' => []],
    ])->assertConflict()->assertJsonPath('message', PersonAccess::STOPPED);

    $this->withToken($token)->putJson("/api/v1/paperwork/v2/people/{$person->id}/processing-restriction", ['stopped' => false])->assertOk();
    $this->withToken($token)->getJson('/api/v1/paperwork/v2/plan')->assertOk()->assertJsonPath('state', 'ready');
});

test('🔒 another person’s record can’t be read or changed from the app', function () {
    $owner = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($owner);
    app(EnsureAccountHolder::class)->dossier($owner);
    $stranger = User::factory()->onboarded()->create();
    $token = $stranger->createToken('iPhone')->plainTextToken;

    $this->withToken($token)->getJson("/api/v1/paperwork/v2/people/{$person->id}/plan?jurisdiction=de-nrw-cologne")->assertForbidden();
    $this->withToken($token)->putJson("/api/v1/paperwork/v2/people/{$person->id}/processing-restriction", ['stopped' => true])->assertForbidden();
    expect($person->fresh()->processing_stopped_at)->toBeNull();
});

test('the app has no route that needs the web’s password check', function () {
    $user = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($user);
    $token = $user->createToken('iPhone')->plainTextToken;

    // Not there (or only its read is): erasing and exporting stay on the web, behind the password.
    expect($this->withToken($token)->deleteJson("/api/v1/paperwork/v2/people/{$person->id}")->status())->toBeIn([404, 405])
        ->and($this->withToken($token)->postJson("/api/v1/paperwork/v2/people/{$person->id}/export")->status())->toBeIn([404, 405])
        ->and($person->fresh())->not->toBeNull();
});
