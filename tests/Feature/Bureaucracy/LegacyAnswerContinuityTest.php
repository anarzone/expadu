<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyRelationship;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

test('an explicitly answered legacy question remains usable without rewriting the answer or inventing a start date', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $question = BureaucracyCaseQuestion::factory()->create([
        'case_id' => $case->id, 'fact_key' => 'current_residence_title',
        'asked_at' => now()->subDay(), 'answered_at' => now()->subDay(), 'outcome' => 'answered',
    ]);
    $fact = $case->facts()->create([
        'key' => 'current_residence_title', 'value' => 'blue_card', 'state' => 'confirmed',
        'source' => 'structured_interview', 'source_reference' => 'question:'.$question->id,
        'confirmed_at' => now()->subDay(), 'reconfirm_at' => now()->addMonth(),
    ]);
    $before = $fact->fresh()->getRawOriginal();

    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());

    expect($view['values']['current_residence_title'] ?? null)->toBe('blue_card')
        ->and($view['evidence']['current_residence_title']['source'])->toBe('structured_interview')
        ->and($view['evidence']['current_residence_title']['effective_from'])->toBeNull()
        ->and($fact->fresh()->getRawOriginal())->toBe($before)
        ->and($case->fresh()->fact_version)->toBe(1);
});

test('legacy interview provenance cannot point to an unanswered different-key or different-person question', function (string $mismatch) {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $question = BureaucracyCaseQuestion::factory()->create([
        'case_id' => $mismatch === 'person' ? app(EnsureAccountHolder::class)->dossier(User::factory()->create())->id : $case->id,
        'fact_key' => $mismatch === 'key' ? 'sponsor_current_title' : 'current_residence_title',
        'asked_at' => now()->subDay(), 'answered_at' => $mismatch === 'unanswered' ? null : now()->subDay(),
        'outcome' => $mismatch === 'skipped' ? 'skipped' : 'answered',
    ]);
    $case->facts()->create([
        'key' => 'current_residence_title', 'value' => 'blue_card', 'state' => 'confirmed',
        'source' => 'structured_interview', 'source_reference' => 'question:'.$question->id,
        'confirmed_at' => now()->subDay(),
    ]);
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values'])->not->toHaveKey('current_residence_title')
        ->and($view['states']['current_residence_title'])->toBe('needs_reconfirmation');
})->with(['person', 'key', 'unanswered', 'skipped']);

test('legacy onboarding does not turn computed citizenship or permit tracks into confirmed answers', function (string $key, string $value) {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $fact = $case->facts()->create([
        'key' => $key, 'value' => $value, 'state' => 'confirmed', 'source' => 'onboarding',
        'confirmed_at' => now()->subDay(),
    ]);
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values'])->not->toHaveKey($key)
        ->and($view['states'][$key])->toBe('needs_reconfirmation')
        ->and($fact->fresh()->value)->toBe($value);
})->with([['citizenship_group', 'non_eu'], ['permit_track', 'blue_card'], ['purpose', 'employment']]);

test('direct legacy onboarding answers remain available but a sponsor answer is still only an attributed report', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    foreach (['current_residence_title' => 'family_reunification', 'sponsor_current_title' => 'blue_card', 'german_level' => 'b1'] as $key => $value) {
        $case->facts()->create(['key' => $key, 'value' => $value, 'state' => 'confirmed', 'source' => 'onboarding', 'confirmed_at' => now()]);
    }
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values'])->toMatchArray(['current_residence_title' => 'family_reunification', 'sponsor_current_title' => 'blue_card', 'german_level' => 'b1'])
        ->and(BureaucracyRelationship::query()->where('person_id', $case->person_id)->count())->toBe(0);
});

test('reconfirming an untrusted stored value records the new confirmation instead of silently returning the old guess', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $guess = $case->facts()->create([
        'key' => 'citizenship_group', 'value' => 'non_eu', 'state' => 'confirmed', 'source' => 'legacy_profile',
        'confirmed_at' => now()->subDay(), 'reconfirm_at' => now()->addYear(),
    ]);

    expect(fn () => app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'non_eu', null, 0))
        ->toThrow(ConflictHttpException::class);
    $confirmed = app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'non_eu', null, 1);
    $again = app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', 'non_eu', null, 1);

    expect($confirmed->id)->not->toBe($guess->id)
        ->and($confirmed->source)->toBe('manual')
        ->and($confirmed->recorded_by)->toBe($actor->id)
        ->and($guess->fresh()->state)->toBe('historical')
        ->and($guess->fresh()->end_date_unknown)->toBeTrue()
        ->and($again->id)->toBe($confirmed->id)
        ->and($case->fresh()->fact_version)->toBe(2)
        ->and(app(ConfirmedFactView::class)->forCase($case, now()->toDateString())['values']['citizenship_group'])->toBe('non_eu');
});

test('reconfirming the same title keeps its occurrence identity and separately confirmed expiry', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $guess = $case->facts()->create(['key' => 'current_residence_title', 'value' => 'blue_card',
        'state' => 'confirmed', 'source' => 'legacy_profile', 'confirmed_at' => now()->subDay()]);
    $expiry = app(RecordFactChange::class)->execute($actor, $case->person, 'residence_title_expires_at', '2027-09-01', null, 1);
    $confirmed = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', null, 2);
    expect($expiry->fresh()->state)->toBe('confirmed')
        ->and($confirmed->context_id)->toBe('fact:'.$guess->id)
        ->and(app(ConfirmedFactView::class)->forCase($case, now()->toDateString())['values']['residence_title_expires_at'] ?? null)->toBe('2027-09-01');
});

test('an identical legacy guess cannot hide a genuine answer merely by being inserted later', function (bool $guessFirst) {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $question = BureaucracyCaseQuestion::factory()->create(['case_id' => $case->id, 'fact_key' => 'citizenship_group', 'answered_at' => now(), 'outcome' => 'answered']);
    $sources = [['source' => 'onboarding'], ['source' => 'structured_interview', 'source_reference' => 'question:'.$question->id]];
    foreach ($guessFirst ? $sources : array_reverse($sources) as $source) {
        $case->facts()->create([...$source, 'key' => 'citizenship_group', 'value' => 'non_eu', 'state' => 'confirmed', 'confirmed_at' => now()]);
    }
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values']['citizenship_group'] ?? null)->toBe('non_eu')
        ->and($view['evidence']['citizenship_group']['source'])->toBe('structured_interview')
        ->and($case->facts()->count())->toBe(2);
})->with([true, false]);
