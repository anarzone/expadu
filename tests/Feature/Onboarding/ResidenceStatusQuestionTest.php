<?php

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\User;

/**
 * Owner review: "we ask how did you enter Germany? I entered with a national D
 * visa, but there's also an option that I already hold a German residence
 * permit. And then we ask which German visa or residence title do you
 * currently hold? That part is confusing."
 *
 * The heading asked about entry while one of its options answered about
 * current status, and the follow-up then re-offered "National D visa" — the
 * choice directly above it. §4 AufenthG does list a Visum as an
 * Aufenthaltstitel, so the wording was not legally wrong; the two questions
 * simply overlapped.
 *
 * The stored facts are deliberately unchanged: entry_mode still separates a
 * first application from a renewal, which is what the rules branch on.
 */
function confirmedFact(User $user, string $key): mixed
{
    $caseId = BureaucracyCase::query()->where('user_id', $user->getKey())->value('id');

    return BureaucracyCaseFact::query()
        ->where('case_id', $caseId)
        ->where('key', $key)
        ->where('state', 'confirmed')
        ->latest('id')
        ->first()
        ?->value;
}

it('still records a D visa entry as both the entry mode and the current title', function () {
    $user = User::factory()->create(['onboarded_at' => null, 'email_verified_at' => now()]);

    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_planned' => false,
        'arrival_date' => now()->subDays(10)->toDateString(),
        'entry_mode' => 'd_visa',
        'current_residence_title' => 'national_d_visa',
        'interests' => [],
    ])->assertSessionHasNoErrors();

    $user->refresh();

    // entry_mode is a profile attribute; current_residence_title is a case
    // fact, so they live in different stores.
    expect($user->profile_attributes['entry_mode'] ?? null)->toBe('d_visa')
        ->and(confirmedFact($user, 'current_residence_title'))->toBe('national_d_visa');
});

it('accepts a real permit as the follow-up to holding one', function () {
    $user = User::factory()->create(['onboarded_at' => null, 'email_verified_at' => now()]);

    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_planned' => false,
        'arrival_date' => now()->subYears(2)->toDateString(),
        'entry_mode' => 'has_permit',
        'current_residence_title' => 'standard_work_permit',
        'residence_title_expires_at' => now()->addYear()->toDateString(),
        'interests' => [],
    ])->assertSessionHasNoErrors();

    expect(confirmedFact($user->fresh(), 'current_residence_title'))
        ->toBe('standard_work_permit');
});
