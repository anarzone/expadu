<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

test('real title changes preserve dated periods while a correction replaces only the erroneous assertion', function () {
    $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $person = $case->person;
    $change = app(RecordFactChange::class);
    $visa = $change->execute($actor, $person, 'current_residence_title', 'national_d_visa', '2022-01-01', 1);
    $blue = $change->execute($actor, $person, 'current_residence_title', 'blue_card', '2022-04-01', 2);
    $settled = $change->execute($actor, $person, 'current_residence_title', 'settlement_permit_9', '2025-04-01', 3);
    $corrected = app(CorrectFact::class)->execute($actor, $person, $settled->id, 'settlement_permit_18c', 4);
    expect($visa->fresh()->state)->toBe('historical')->and($visa->fresh()->effective_until->toDateString())->toBe('2022-04-01')
        ->and($blue->fresh()->state)->toBe('historical')->and($blue->fresh()->effective_until->toDateString())->toBe('2025-04-01')
        ->and($settled->fresh()->state)->toBe('superseded')
        ->and($corrected->effective_from->toDateString())->toBe('2025-04-01')
        ->and($corrected->supersedes_fact_id)->toBe($settled->id)
        ->and($case->fresh()->fact_version)->toBe(5);
    $view = app(ConfirmedFactView::class);
    expect($view->forCase($case->fresh(), '2022-02-01')['values']['current_residence_title'])->toBe('national_d_visa')
        ->and($view->forCase($case->fresh(), '2024-01-01')['values']['current_residence_title'])->toBe('blue_card')
        ->and($view->forCase($case->fresh(), '2026-09-08')['values']['current_residence_title'])->toBe('settlement_permit_18c');
});

test('unknown change dates do not invent qualifying periods and repeating an assertion is idempotent', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $person = $case->person;
    $first = app(RecordFactChange::class)->execute($actor, $person, 'current_residence_title', 'blue_card', '2023-01-01', 1);
    $unknownDate = app(RecordFactChange::class)->execute($actor, $person, 'current_residence_title', 'settlement_permit_unknown', null, 2);
    $again = app(RecordFactChange::class)->execute($actor, $person, 'current_residence_title', 'settlement_permit_unknown', null, 2);
    expect($again->id)->toBe($unknownDate->id)->and($unknownDate->effective_from)->toBeNull()
        ->and($first->fresh()->end_date_unknown)->toBeTrue()->and($case->fresh()->fact_version)->toBe(3);
});

test('stale edits, another persons fact and overlapping changes cannot overwrite history', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $person = $case->person;
    $fact = app(RecordFactChange::class)->execute($actor, $person, 'current_residence_title', 'blue_card', '2024-01-01', 1);
    expect(fn () => app(CorrectFact::class)->execute($actor, $person, $fact->id, 'other', 1))->toThrow(ConflictHttpException::class);
    expect(fn () => app(RecordFactChange::class)->execute($actor, $person, 'current_residence_title', 'other', '2023-01-01', 2))->toThrow(ValidationException::class);
    $other = User::factory()->onboarded()->create();
    $otherPerson = app(EnsureAccountHolder::class)->dossier($other)->person;
    expect(fn () => app(CorrectFact::class)->execute($other, $otherPerson, $fact->id, 'other', 1))->toThrow(AuthorizationException::class);
    expect($fact->fresh()->value)->toBe('blue_card')->and(BureaucracyCaseFact::query()->where('case_id', $case->id)->count())->toBe(1);
});

test('unknown and declined answers stay explicit and are not false or inferred citizenship', function (string $answerState) {
    $actor = User::factory()->onboarded()->create(['is_eu' => false]);
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $fact = app(RecordFactChange::class)->execute($actor, $case->person, 'citizenship_group', null, null, 1, $answerState);
    $view = app(ConfirmedFactView::class)->forCase($case->fresh(), now()->toDateString());
    expect($fact->value)->toBeNull()->and($view['states']['citizenship_group'])->toBe($answerState)
        ->and($view['values'])->not->toHaveKey('citizenship_group')
        ->and($fact->getRawOriginal('value'))->not->toContain('non_eu');
})->with(['unknown', 'declined']);

test('a corrected historic assertion preserves its original period and never replaces the current title', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $old = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'national_d_visa', '2023-01-01', 1);
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2023-05-01', 2);
    $corrected = app(CorrectFact::class)->execute($actor, $case->person, $old->id, 'standard_work_permit', 3);
    expect($corrected->state)->toBe('historical')
        ->and($corrected->effective_until->toDateString())->toBe('2023-05-01')
        ->and(app(ConfirmedFactView::class)->forCase($case->fresh(), now()->toDateString())['values']['current_residence_title'])->toBe('blue_card');
});

test('a new residence title does not reuse the previous titles expiry', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2024-01-01', 1);
    $expiry = $change->execute($actor, $case->person, 'residence_title_expires_at', '2027-06-01', null, 2);
    $change->execute($actor, $case->person, 'current_residence_title', 'settlement_permit_unknown', '2026-08-01', 3);
    $view = app(ConfirmedFactView::class)->forCase($case->fresh(), now()->toDateString());
    expect($view['values'])->not->toHaveKey('residence_title_expires_at')
        ->and($expiry->fresh()->value)->toBe('2027-06-01')
        ->and($expiry->fresh()->state)->toBe('historical');
});

test('the canonical view quarantines unreviewed legacy guesses and preserves expiry time within a day', function () {
    $this->travelTo(now()->setTime(10, 0));
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $legacy = $case->facts()->create(['key' => 'citizenship_group', 'value' => 'non_eu', 'state' => 'confirmed', 'source' => 'legacy_profile', 'confirmed_at' => now()]);
    $current = app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', null, 1);
    $current->update(['reconfirm_at' => now()->setTime(18, 0)]);
    $view = app(ConfirmedFactView::class)->forCase($case->fresh(), now()->toDateString());
    expect($view['values'])->not->toHaveKey('citizenship_group')
        ->and($view['states']['citizenship_group'])->toBe('needs_reconfirmation')
        ->and($view['values']['german_level'])->toBe('b1');
});

test('same-type permit renewal retires the previous document and residence expiry', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2024-01-01', 1);
    $change->execute($actor, $case->person, 'residence_title_expires_at', '2026-08-01', null, 2);
    $change->execute($actor, $case->person, 'residence_card_expires_at', '2026-08-01', null, 3);
    $change->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2026-08-01', 4);
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values'])->not->toHaveKey('residence_title_expires_at')->not->toHaveKey('residence_card_expires_at')
        ->and($case->facts()->where('key', 'current_residence_title')->count())->toBe(2);
});

test('an undated change cannot allow a later command to overlap a known historical period', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'current_residence_title', 'national_d_visa', '2022-01-01', 1);
    $change->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2023-01-01', 2);
    $change->execute($actor, $case->person, 'current_residence_title', 'settlement_permit_unknown', null, 3);
    expect(fn () => $change->execute($actor, $case->person, 'current_residence_title', 'other', '2022-06-01', 4))
        ->toThrow(ValidationException::class);
    expect($case->fresh()->fact_version)->toBe(4)
        ->and(app(ConfirmedFactView::class)->forCase($case, '2022-07-01')['values']['current_residence_title'])->toBe('national_d_visa');
});

test('a current title conflict does not erase an undisputed earlier visa period', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'current_residence_title', 'national_d_visa', '2022-01-01', 1);
    $blue = $change->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2024-01-01', 2);
    $candidate = $case->facts()->create(['key' => 'current_residence_title', 'value' => 'other', 'state' => 'candidate', 'source' => 'manual']);
    BureaucracyFactConflict::query()->create(['case_id' => $case->id, 'fact_key' => 'current_residence_title', 'existing_fact_id' => $blue->id, 'candidate_fact_id' => $candidate->id]);
    $view = app(ConfirmedFactView::class);
    expect($view->forCase($case, '2023-01-01')['values']['current_residence_title'])->toBe('national_d_visa')
        ->and($view->forCase($case, now()->toDateString())['states']['current_residence_title'])->toBe('conflict');
});
