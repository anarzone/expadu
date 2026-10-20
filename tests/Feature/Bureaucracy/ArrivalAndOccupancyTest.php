<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('actual occupancy survives a missing housing confirmation and does not mean registration is complete', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'moved_in_at', '2026-08-01', null, 1);
    $change->execute($actor, $case->person, 'housing_provider_confirmation', 'not_available', null, 2);
    $view = app(ConfirmedFactView::class)->forCase($case->fresh(), now()->toDateString());
    expect($view['values']['moved_in_at'])->toBe('2026-08-01')
        ->and($view['values']['housing_provider_confirmation'])->toBe('not_available')
        ->and($view['values'])->not->toHaveKey('registration_status');
});

test('planned arrival is not an actual arrival and inconsistent or future historical dates are rejected', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'arrival_planned', true, null, 1);
    expect(fn () => $change->execute($actor, $case->person, 'arrival_date', '2026-08-01', null, 2))->toThrow(ValidationException::class);
    expect(fn () => $change->execute($actor, $case->person, 'moved_in_at', '2026-02-30', null, 2))->toThrow(ValidationException::class);
    expect(fn () => $change->execute($actor, $case->person, 'moved_in_at', now()->addDay()->toDateString(), null, 2))->toThrow(ValidationException::class);
    $change->execute($actor, $case->person, 'arrival_planned', false, null, 2);
    $change->execute($actor, $case->person, 'arrival_date', '2026-08-01', null, 3);
    expect(app(ConfirmedFactView::class)->forCase($case->fresh(), now()->toDateString())['values']['arrival_date'])->toBe('2026-08-01');
});

test('physical card expiry does not make permanent residence temporary', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'current_residence_title', 'settlement_permit_unknown', null, 1);
    $change->execute($actor, $case->person, 'residence_card_expires_at', '2027-06-01', null, 2);
    $view = app(ConfirmedFactView::class)->forCase($case->fresh(), now()->toDateString());
    expect($view['values']['residence_card_expires_at'])->toBe('2027-06-01')
        ->and($view['values'])->not->toHaveKey('residence_title_expires_at');
});

test('a historical occupancy correction is checked against arrival during that period', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $change = app(RecordFactChange::class);
    $change->execute($actor, $case->person, 'arrival_date', '2026-08-01', '2026-08-01', 1);
    $old = $change->execute($actor, $case->person, 'moved_in_at', '2026-08-05', '2026-08-05', 2);
    $change->execute($actor, $case->person, 'moved_in_at', '2026-09-01', '2026-09-01', 3);
    expect(fn () => app(CorrectFact::class)->execute($actor, $case->person, $old->id, '2026-07-31', 4))
        ->toThrow(ValidationException::class);
    expect($old->fresh()->state)->toBe('historical')->and($case->fresh()->fact_version)->toBe(4);
});
