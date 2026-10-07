<?php

use App\Bureaucracy\PathGenerator;
use App\Models\Task;
use App\Models\User;
use App\Profile\Applicability;
use App\Profile\ProfileEngine;

/**
 * Citizenship used to live inside the permanent-residency card, which is gated
 * on NOT already being settled. That gate is right for its own half — do not
 * offer permanent residency to someone who holds it — and exactly wrong for
 * citizenship, which counts years of residence rather than years since
 * permanent residency. The effect was that the one group for whom citizenship
 * is the next real question were the only ones who never saw it.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();
});

function applicabilityFor(string $key, User $user): Applicability
{
    return app(PathGenerator::class)->applicability(
        Task::query()->where('key', $key)->firstOrFail(),
        app(ProfileEngine::class)->build($user),
    );
}

/** The settlement card is gated on the v2 residence-title fact, which the legacy profile does not carry. */
function longGameFor(string $title): Applicability
{
    return Applicability::evaluate(Task::query()->where('key', 'shared.long_game')->firstOrFail()->applies_if,
        ['citizenship_group' => 'non_eu', 'current_residence_title' => $title]);
}

it('shows citizenship to someone who already holds permanent residence', function () {
    $settled = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'profile_attributes' => ['current_residence_title' => 'settlement_permit_18c'],
    ]);

    expect(applicabilityFor('shared.citizenship', $settled))->toBe(Applicability::Yes)
        // ...and still does not offer them the thing they already have.
        ->and(longGameFor('settlement_permit_18c'))->toBe(Applicability::No);
});

it('still shows both to someone who is not settled yet', function () {
    $arriving = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'profile_attributes' => ['current_residence_title' => 'standard_work_permit'],
    ]);

    expect(applicabilityFor('shared.citizenship', $arriving))->toBe(Applicability::Yes)
        ->and(longGameFor('standard_work_permit'))->toBe(Applicability::Yes);
});

it('keeps the citizenship figures backed by quotes from the law and the city', function () {
    $citizenship = Task::query()->where('key', 'shared.citizenship')->firstOrFail();
    $longGame = Task::query()->where('key', 'shared.long_game')->firstOrFail();

    // Every figure is quoted (§10 StAG, Stadt Köln); the unsourced remark about the
    // abolished fast track was removed when the card moved to the automated check.
    expect($citizenship->description)->toContain('five years of lawful residence')->toContain('level B1')->toContain('€255')
        ->and($citizenship->source_verification)->toBe('quote_checked')
        ->and($citizenship->description)->not->toContain('abolished')
        // And it must not have been left behind in the card it came from.
        ->and($longGame->description)->not->toContain('Einbürgerungstest');
});
