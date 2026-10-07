<?php

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\User;
use App\Onboarding\CompleteBureaucracyOnboarding;
use App\Onboarding\ReviewBureaucracyDraft;
use App\Onboarding\SaveBureaucracyDraft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function draftFixture(): array
{
    $actor = User::factory()->create();

    return [$actor, app(EnsureAccountHolder::class)->dossier($actor)];
}

test('partial drafts are encrypted and reload without confirming facts', function () {
    [$actor, $case] = draftFixture();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['arrival_date' => ['value' => '08/0']]);
    $read = app(SaveBureaucracyDraft::class)->read($actor, $case->person);
    expect($read['step'])->toBe(2)->and($read['answers']['arrival_date']['value'])->toBe('08/0')
        ->and(DB::table('bureaucracy_onboarding_drafts')->where('id', $draft->id)->value('payload'))->not->toContain('08/0')
        ->and($case->facts()->count())->toBe(0);
    $stranger = User::factory()->create();
    expect(fn () => app(SaveBureaucracyDraft::class)->read($stranger, $case->person))->toThrow(AuthorizationException::class);
});

test('onboarding can finish with every answer skipped without guessing identity', function () {
    [$actor, $case] = draftFixture();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 1, []);
    $request = (string) Str::uuid();
    $complete = app(CompleteBureaucracyOnboarding::class);
    $result = $complete->execute($actor, $case->person, $draft->id, 1, 1, $request);
    expect($result['fact_revision'])->toBe(1)->and($case->facts()->count())->toBe(0)
        ->and($actor->fresh()->onboarded_at)->not->toBeNull()->and($draft->fresh()->payload)->toBeNull()
        ->and(app(SaveBureaucracyDraft::class)->read($actor, $case->person))->toBeNull()
        ->and($complete->execute($actor, $case->person, $draft->id, 1, 1, $request))->toBe($result);
});

test('stale draft saves and confirmations cannot replace newer input', function () {
    [$actor, $case] = draftFixture();
    $save = app(SaveBureaucracyDraft::class);
    $draft = $save->execute($actor, $case->person, (string) Str::uuid(), 0, 1, ['citizenship_group' => ['value' => 'non_eu']]);
    $save->execute($actor, $case->person, $draft->id, 1, 2, ['citizenship_group' => ['value' => 'eu']]);
    expect(fn () => $save->execute($actor, $case->person, $draft->id, 1, 1, []))->toThrow(ConflictHttpException::class);
    app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'eu', null, 1);
    expect(fn () => app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 2, 1, (string) Str::uuid()))->toThrow(ConflictHttpException::class);
    expect($draft->fresh()->payload['answers']['citizenship_group']['value'])->toBe('eu');
});

test('final validation is strict field-specific and atomic', function (string $badDate) {
    [$actor, $case] = draftFixture();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, [
        'citizenship_group' => ['value' => 'non_eu'], 'arrival_date' => ['value' => $badDate],
    ]);
    try {
        app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 1, (string) Str::uuid());
        test()->fail('An invalid actual arrival must not complete onboarding.');
    } catch (ValidationException $error) {
        expect($error->errors())->toHaveKey('answers.arrival_date.value');
    }
    expect($case->facts()->count())->toBe(0)->and($draft->fresh()->status)->toBe('active');
})->with(['1', '2026-02-30', '2099-01-01']);

test('context edits remove only invalid draft answers and preserve real history', function () {
    [$actor, $case] = draftFixture();
    app(RecordFactChange::class)->execute($actor, $case->person, 'arrival_date', '2026-01-01', null, 1);
    $save = app(SaveBureaucracyDraft::class);
    $draft = $save->execute($actor, $case->person, (string) Str::uuid(), 0, 2, [
        'arrival_planned' => ['value' => false], 'arrival_date' => ['value' => '2026-01-01'],
        'moved_in_at' => ['value' => '2026-02-01'], 'current_residence_title' => ['value' => 'blue_card'],
        'residence_title_expires_at' => ['value' => '2026-12-01'],
    ]);
    $draft = $save->execute($actor, $case->person, $draft->id, 1, 2, [
        'arrival_planned' => ['value' => true], 'current_residence_title' => ['value' => 'settlement_permit_unknown'],
    ]);
    expect($draft->payload['answers'])->not->toHaveKeys(['arrival_date', 'moved_in_at', 'residence_title_expires_at'])
        ->and($case->facts()->where('state', 'confirmed')->sole()->value)->toBe('2026-01-01');
});

test('occupancy survives missing proof and is confirmed in the canonical record', function () {
    [$actor, $case] = draftFixture();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, [
        'arrival_planned' => ['value' => false], 'arrival_date' => ['value' => '2026-01-01'],
        'moved_in_at' => ['value' => '2026-02-01'], 'housing_provider_confirmation' => ['value' => 'not_available'],
    ]);
    app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 1, (string) Str::uuid());
    $facts = $case->facts()->where('state', 'confirmed')->get()->keyBy('key');
    expect($facts['moved_in_at']->value)->toBe('2026-02-01')->and($facts['moved_in_at']->source)->toBe('onboarding')
        ->and($facts['housing_provider_confirmation']->value)->toBe('not_available');
});

test('a conflicting assertion requires an explicit correction or real change', function () {
    [$actor, $case] = draftFixture();
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['current_residence_title' => ['value' => 'settlement_permit_18c']]);
    expect(fn () => app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 2, (string) Str::uuid()))->toThrow(ValidationException::class);
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, $draft->id, 1, 2, [
        'current_residence_title' => ['value' => 'settlement_permit_18c', 'operation' => 'change', 'effective_from' => '2026-08-01'],
    ]);
    app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 2, 2, (string) Str::uuid());
    expect($case->facts()->where('state', 'historical')->sole()->value)->toBe('blue_card')
        ->and($case->facts()->where('state', 'confirmed')->sole()->value)->toBe('settlement_permit_18c');
});

test('expired drafts cannot be resumed or confirmed and never erase facts', function () {
    [$actor, $case] = draftFixture();
    app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'non_eu', null, 1);
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 1, []);
    $this->travel(31)->days();
    expect(app(SaveBureaucracyDraft::class)->read($actor, $case->person))->toBeNull();
    expect(fn () => app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 2, (string) Str::uuid()))->toThrow(ConflictHttpException::class);
    expect($case->facts()->count())->toBe(1);
});

test('automatic reconfirmation cannot dismiss a conflict between current assertions', function () {
    [$actor, $case] = draftFixture();
    $one = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    $copy = $one->replicate();
    $copy->value = 'settlement_permit_18c';
    $copy->save();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['current_residence_title' => ['value' => 'settlement_permit_18c']]);
    expect(fn () => app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 2, (string) Str::uuid()))->toThrow(ConflictHttpException::class);
    expect($case->facts()->count())->toBe(2)->and($case->fresh()->fact_version)->toBe(2);
});

test('review never promises a new period date for a correction', function () {
    [$actor, $case] = draftFixture();
    $fact = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, [
        'current_residence_title' => ['value' => 'standard_work_permit', 'operation' => 'correct', 'corrects_fact_id' => $fact->id, 'effective_from' => '2026-08-01'],
    ]);
    expect(fn () => app(ReviewBureaucracyDraft::class)->for($actor, $case->person))->toThrow(ValidationException::class);
    expect($case->facts()->count())->toBe(1);
});

test('completion accepts identical uppercase UUID retries and does not write the facts twice', function () {
    [$actor, $case] = draftFixture();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, strtoupper((string) Str::uuid()), 0, 1, []);
    $request = strtoupper((string) Str::uuid());
    $complete = app(CompleteBureaucracyOnboarding::class);
    $one = $complete->execute($actor, $case->person, $draft->id, 1, 1, $request);
    expect($complete->execute($actor, $case->person, $draft->id, 1, 1, $request))->toBe($one);
});

test('reconfirming identical current answers is a no-op and review displays their real period', function () {
    [$actor, $case] = draftFixture();
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['current_residence_title' => ['value' => 'blue_card']]);
    expect(app(ReviewBureaucracyDraft::class)->for($actor, $case->person)['answers'][0]['effective_from'])->toBe('2025-01-01');
    app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 2, (string) Str::uuid());
    expect($case->facts()->count())->toBe(1)->and($case->fresh()->fact_version)->toBe(2);
});

test('identical duplicate assertions do not become a false conflict during onboarding review', function () {
    [$actor, $case] = draftFixture();
    $fact = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    $fact->replicate()->save();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 2, ['current_residence_title' => ['value' => 'blue_card']]);
    app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 2, (string) Str::uuid());
    expect($case->facts()->count())->toBe(2)->and($case->fresh()->fact_version)->toBe(2);
});
