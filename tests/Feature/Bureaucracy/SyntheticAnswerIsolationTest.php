<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\ReadFactConflicts;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use App\Onboarding\ApplyOnboardingAnswers;
use App\Onboarding\CompleteBureaucracyOnboarding;
use App\Onboarding\ReviewBureaucracyDraft;
use App\Onboarding\SaveBureaucracyDraft;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('a genuine answer is not disputed by retained synthetic assertions or their stale conflict row', function (string $source) {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $real = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', null, 1);
    $sample = $case->facts()->create(['key' => 'current_residence_title', 'value' => 'family_reunification',
        'state' => 'confirmed', 'source' => $source, 'confirmed_at' => now()]);
    $conflict = BureaucracyFactConflict::query()->create(['case_id' => $case->id, 'fact_key' => $real->key,
        'existing_fact_id' => $sample->id, 'candidate_fact_id' => $real->id]);
    $before = $sample->fresh()->getRawOriginal();
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values']['current_residence_title'] ?? null)->toBe('blue_card')
        ->and(app(ReadFactConflicts::class)->for($actor, $case->person)['conflicts'])->toBe([])
        ->and($sample->fresh()->getRawOriginal())->toBe($before)
        ->and($conflict->fresh()->status)->toBe('unresolved');
})->with(['qa_scenario:sample', 'qa_persona:sample']);

test('confirmed onboarding retires only sample assertions and retains genuine answers and raw sample history', function () {
    $actor = User::factory()->notOnboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $real = app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', null, 1);
    $realBefore = $real->fresh()->getRawOriginal();
    $sample = $case->facts()->create(['key' => 'current_residence_title', 'value' => 'blue_card',
        'state' => 'confirmed', 'source' => 'qa_scenario:sample', 'confirmed_at' => now()]);
    app(ApplyOnboardingAnswers::class)->execute($actor, ['current_residence_title' => 'family_reunification', 'interests' => []]);
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values']['current_residence_title'])->toBe('family_reunification')
        ->and($real->fresh()->getRawOriginal())->toBe($realBefore)
        ->and($sample->fresh()->state)->toBe('superseded')
        ->and($sample->fresh()->value)->toBe('blue_card')
        ->and($sample->fresh()->source)->toBe('qa_scenario:sample');
});

test('a genuine conflict offers only genuine choices even when a sample candidate remains', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $real = app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', null, 1);
    $other = $case->facts()->create(['key' => 'german_level', 'value' => 'b2', 'state' => 'confirmed', 'source' => 'manual', 'confirmed_at' => now()]);
    $sample = $case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => 'candidate', 'source' => 'qa_scenario:sample']);
    BureaucracyFactConflict::query()->create(['case_id' => $case->id, 'fact_key' => $real->key,
        'existing_fact_id' => $real->id, 'candidate_fact_id' => $sample->id]);
    $conflicts = app(ReadFactConflicts::class)->for($actor, $case->person)['conflicts'];
    expect($conflicts)->toHaveCount(1)
        ->and(array_column($conflicts[0]['choices'], 'fact_id'))->toBe([$real->id, $other->id]);
});

test('a synthetic candidate does not turn a genuine earlier period into a historical dispute', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $real = app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', now()->subDays(10)->toDateString(), 1);
    $sample = $case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => 'candidate', 'source' => 'qa_scenario:sample']);
    BureaucracyFactConflict::query()->create(['case_id' => $case->id, 'fact_key' => $real->key,
        'existing_fact_id' => $real->id, 'candidate_fact_id' => $sample->id]);
    app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b2', now()->toDateString(), 2);
    $past = app(ConfirmedFactView::class)->forCase($case, now()->subDay()->toDateString());
    expect($past['values']['german_level'] ?? null)->toBe('b1');
});

test('failed real answer confirmation rolls back sample retirement and fact revisions', function () {
    $actor = User::factory()->notOnboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', null, 1);
    $sample = $case->facts()->create(['key' => 'current_residence_title', 'value' => 'blue_card',
        'state' => 'confirmed', 'source' => 'qa_scenario:sample', 'confirmed_at' => now()]);
    $before = $sample->fresh()->getRawOriginal();
    expect(fn () => app(ApplyOnboardingAnswers::class)->execute($actor, ['documented_german_level' => 'b2', 'interests' => []]))
        ->toThrow(ValidationException::class);
    expect($sample->fresh()->getRawOriginal())->toBe($before)->and($case->fresh()->fact_version)->toBe(2)
        ->and($actor->fresh()->onboarded_at)->toBeNull();
});

test('sample history cannot prevent a genuine answer from starting in an overlapping period', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $sample = $case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => 'historical',
        'source' => 'qa_scenario:sample', 'confirmed_at' => now()->subMonth(),
        'effective_from' => now()->subMonth()->toDateString(), 'effective_until' => now()->subDay()->toDateString()]);
    $before = $sample->fresh()->getRawOriginal();
    $real = app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', now()->subWeek()->toDateString(), 1);
    expect($real->operation)->toBe('assertion')->and($real->source)->toBe('manual')
        ->and($sample->fresh()->getRawOriginal())->toBe($before);
});

test('real history still prevents a new change from overlapping an earlier period', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => 'historical',
        'source' => 'manual', 'confirmed_at' => now()->subMonth(),
        'effective_from' => now()->subMonth()->toDateString(), 'effective_until' => now()->subDay()->toDateString()]);
    expect(fn () => app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', now()->subWeek()->toDateString(), 1))
        ->toThrow(ValidationException::class);
    expect($case->facts()->count())->toBe(1)->and($case->fresh()->fact_version)->toBe(1);
});

test('a real assertion never inherits the context or change history of a current sample', function (string $value) {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $sample = $case->facts()->create(['key' => 'german_level', 'value' => 'b1', 'state' => 'confirmed',
        'source' => 'qa_scenario:sample', 'confirmed_at' => now(), 'context_id' => (string) Str::uuid()]);
    $before = $sample->fresh()->getRawOriginal();
    $real = app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', $value, null, 1);
    expect($real->operation)->toBe('assertion')->and($real->context_id)->not->toBe($sample->context_id)
        ->and($real->supersedes_fact_id)->toBeNull()->and($sample->fresh()->getRawOriginal())->toBe($before)
        ->and(app(ConfirmedFactView::class)->forCase($case, now()->toDateString())['values']['german_level'])->toBe($value);
})->with(['b1', 'b2']);

test('sample assertions cannot be promoted into real answers through direct correction', function (string $state) {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $sample = $case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => $state,
        'source' => 'qa_persona:sample', 'confirmed_at' => now(), 'effective_from' => now()->subMonth()->toDateString()]);
    $before = $sample->fresh()->getRawOriginal();
    expect(fn () => app(CorrectFact::class)->execute($actor, $case->person, $sample->id, 'b1', 1))
        ->toThrow(AuthorizationException::class);
    expect($sample->fresh()->getRawOriginal())->toBe($before)->and($case->fresh()->fact_version)->toBe(1)
        ->and($case->facts()->count())->toBe(1);
})->with(['confirmed', 'historical']);

test('sample archival is person scoped preserves genuine candidates and is idempotent on completion replay', function () {
    $actor = User::factory()->notOnboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $other = app(EnsureAccountHolder::class)->dossier(User::factory()->create());
    $sample = $case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => 'confirmed', 'source' => 'qa_scenario:sample']);
    $candidate = $case->facts()->create(['key' => 'german_level', 'value' => 'a2', 'state' => 'candidate', 'source' => 'qa_persona:sample']);
    $realCandidate = $case->facts()->create(['key' => 'german_level', 'value' => 'b1', 'state' => 'candidate', 'source' => 'manual']);
    $otherSample = $other->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => 'confirmed', 'source' => 'qa_scenario:sample']);
    $conflict = BureaucracyFactConflict::query()->create(['case_id' => $case->id, 'fact_key' => 'german_level',
        'existing_fact_id' => $sample->id, 'candidate_fact_id' => $candidate->id]);
    $preserved = [$realCandidate->fresh()->getRawOriginal(), $otherSample->fresh()->getRawOriginal(), $other->fresh()->getRawOriginal()];
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 1, []);
    $request = (string) Str::uuid();
    $complete = app(CompleteBureaucracyOnboarding::class);
    $receipt = $complete->execute($actor, $case->person, $draft->id, 1, 1, $request);
    $events = DB::table('bureaucracy_outbox_events')->orderBy('id')->get()->toJson();
    $personRevision = $case->person->fresh()->record_version;
    expect($sample->fresh()->state)->toBe('superseded')->and($candidate->fresh()->state)->toBe('superseded')
        ->and($conflict->fresh()->status)->toBe('obsolete')->and($conflict->fresh()->resolved_fact_id)->toBeNull()
        ->and([$realCandidate->fresh()->getRawOriginal(), $otherSample->fresh()->getRawOriginal(), $other->fresh()->getRawOriginal()])->toBe($preserved)
        ->and($complete->execute($actor, $case->person, $draft->id, 1, 1, $request))->toBe($receipt)
        ->and($case->fresh()->fact_version)->toBe($receipt['fact_revision'])
        ->and($case->person->fresh()->record_version)->toBe($personRevision)
        ->and(DB::table('bureaucracy_outbox_events')->orderBy('id')->get()->toJson())->toBe($events);
});

test('onboarding review and completion both reject correction references to sample answers', function (string $state) {
    $actor = User::factory()->notOnboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $sample = $case->facts()->create(['key' => 'german_level', 'value' => 'a1', 'state' => $state,
        'source' => 'qa_scenario:sample', 'confirmed_at' => now(), 'effective_from' => now()->subMonth()->toDateString()]);
    $before = $sample->fresh()->getRawOriginal();
    $draft = app(SaveBureaucracyDraft::class)->execute($actor, $case->person, (string) Str::uuid(), 0, 1, [
        'german_level' => ['value' => 'b1', 'operation' => 'correct', 'corrects_fact_id' => $sample->id],
    ]);
    expect(fn () => app(ReviewBureaucracyDraft::class)->for($actor, $case->person))->toThrow(ValidationException::class);
    expect(fn () => app(CompleteBureaucracyOnboarding::class)->execute($actor, $case->person, $draft->id, 1, 1, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect($sample->fresh()->getRawOriginal())->toBe($before)->and($case->fresh()->fact_version)->toBe(1)
        ->and($case->facts()->count())->toBe(1)->and($draft->fresh()->status)->toBe('active');
})->with(['confirmed', 'historical']);
