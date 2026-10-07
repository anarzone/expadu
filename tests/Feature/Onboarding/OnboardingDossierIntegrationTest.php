<?php

use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Models\User;

/** The three required answers; tests add the optional answers they exercise. */
function coreOnboarding(array $answers = []): array
{
    return array_replace(['situation' => 'other', 'veedel' => 'Ehrenfeld', 'arrival_planned' => false, 'arrival_date' => '2026-01-01'], $answers);
}

test('the existing onboarding route accepts every optional bureaucracy field skipped', function () {
    $actor = User::factory()->create(['situation' => null, 'is_eu' => null, 'veedel' => null, 'arrival_date' => null]);
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding())->assertSessionHasNoErrors()->assertRedirect('/bureaucracy');
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    // Only the three required answers become facts; nothing optional is invented.
    expect($case->facts()->pluck('key')->sort()->values()->all())->toBe(['arrival_date', 'arrival_planned', 'purpose'])
        ->and($actor->fresh()->onboarded_at)->not->toBeNull()
        ->and($actor->fresh()->bureaucracy_path)->toBeNull()->and($actor->fresh()->is_eu)->toBeNull();
});

test('a skipped citizenship follow-up does not turn a student or family member into a non-EU citizen', function (string $situation) {
    $actor = User::factory()->create(['situation' => null, 'is_eu' => null]);
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding(['situation' => $situation]))->assertSessionHasNoErrors();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    expect($case->facts()->where('key', 'citizenship_group')->count())->toBe(0)
        ->and($case->facts()->where('key', 'purpose')->sole()->source)->toBe('onboarding');
})->with(['student', 'family_reunification']);

test('the legacy form preserves actual occupancy even without registration proof', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding([
        'moved_in_at' => '2026-02-01', 'address_registration_status' => 'not_registrable',
    ]))->assertSessionHasNoErrors();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    expect($case->facts()->where('key', 'moved_in_at')->sole()->value)->toBe('2026-02-01')
        ->and($case->facts()->where('key', 'registration_status')->count())->toBe(0);
});

test('permanent residence has no invented legal expiry or settlement date', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding(['current_residence_title' => 'settlement_permit_unknown',
        'residence_card_expires_at' => '2027-01-01']))->assertSessionHasNoErrors();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    expect($case->facts()->where('key', 'current_residence_title')->sole()->value)->toBe('settlement_permit_unknown')
        ->and($case->facts()->where('key', 'residence_title_expires_at')->count())->toBe(0)
        ->and($case->facts()->where('key', 'residence_card_expires_at')->sole()->value)->toBe('2027-01-01')
        ->and($actor->fresh()->profile_attributes['settled_at'] ?? null)->toBeNull();
});

test('redoing onboarding and skipping answers does not erase confirmed history', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding())->assertSessionHasNoErrors();
    expect($case->facts()->where('key', 'current_residence_title')->where('state', 'confirmed')->sole()->value)->toBe('blue_card')
        ->and($case->facts()->where('key', 'current_residence_title')->count())->toBe(1);
});

test('legacy onboarding exposes exact date validation on the original field', function (string $date) {
    $actor = User::factory()->create();
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding(['arrival_date' => $date]))
        ->assertSessionHasErrors('arrival_date');
    expect($actor->fresh()->onboarded_at)->toBeNull();
})->with(['1', '2026-02-30', '2099-01-01']);

test('a selected Cologne neighbourhood supplies the guidance location without asserting arrival', function () {
    $actor = User::factory()->create(['city' => null]);
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding(['arrival_planned' => true, 'arrival_date' => null]))->assertSessionHasNoErrors();
    expect($actor->fresh()->city)->toBe('Köln')
        ->and(app(AccountHolderPlan::class)->for($actor->fresh())['jurisdiction'])->toBe('de-nrw-cologne');
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    expect($case->facts()->where('key', 'arrival_date')->count())->toBe(0)
        ->and($case->facts()->where('key', 'arrival_planned')->sole()->value)->toBeTrue();
});

test('a neighbourhood is required so places, commute and the guidance location never guess a city', function () {
    $actor = User::factory()->create(['city' => 'Berlin', 'onboarded_at' => null]);
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding(['veedel' => null]))->assertSessionHasErrors('veedel');
    expect($actor->fresh()->city)->toBe('Berlin')->and($actor->fresh()->onboarded_at)->toBeNull();
});

test('confirming onboarding upgrades an old inferred citizenship answer even when the value is unchanged', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $guess = $case->facts()->create(['key' => 'citizenship_group', 'value' => 'non_eu', 'state' => 'confirmed',
        'source' => 'onboarding', 'confirmed_at' => now()->subDay(), 'reconfirm_at' => now()->addYear()]);
    $this->actingAs($actor)->post('/onboarding/complete', coreOnboarding(['situation' => 'non_eu_employee']))->assertSessionHasNoErrors();
    $view = app(ConfirmedFactView::class)->forCase($case, now()->toDateString());
    expect($view['values']['citizenship_group'] ?? null)->toBe('non_eu')
        ->and($view['evidence']['citizenship_group']['fact_id'] ?? null)->not->toBe($guess->id)
        ->and($case->facts()->where('key', 'citizenship_group')->where('state', 'confirmed')->sole()->recorded_by)->toBe($actor->id);
});

test('a changed situation survives the redirect as a visible error on the original onboarding field', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    app(RecordFactChange::class)->execute($actor, $case->person, 'purpose', 'family', null, 1);
    $this->actingAs($actor)->from('/onboarding')->post('/onboarding/complete', coreOnboarding(['situation' => 'student']))->assertStatus(302);
    $this->get('/onboarding')->assertInertia(fn ($page) => $page->has('errors.situation')->missing('errors.purpose'));
    expect($case->fresh()->fact_version)->toBe(2)
        ->and($case->facts()->where('key', 'purpose')->sole()->value)->toBe('family');
});
