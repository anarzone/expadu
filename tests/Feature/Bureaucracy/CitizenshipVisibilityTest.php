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

it('shows citizenship to someone who already holds permanent residence', function () {
    $settled = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'profile_attributes' => ['settled_at' => now()->subYear()->toDateString()],
    ]);

    expect(applicabilityFor('shared.citizenship', $settled))->toBe(Applicability::Yes)
        // ...and still does not offer them the thing they already have.
        ->and(applicabilityFor('shared.long_game', $settled))->toBe(Applicability::No);
});

it('still shows both to someone who is not settled yet', function () {
    $arriving = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'profile_attributes' => [],
    ]);

    expect(applicabilityFor('shared.citizenship', $arriving))->toBe(Applicability::Yes)
        ->and(applicabilityFor('shared.long_game', $arriving))->toBe(Applicability::Yes);
});

it('keeps the citizenship figures exactly as they were reviewed', function () {
    $citizenship = Task::query()->where('key', 'shared.citizenship')->firstOrFail();
    $longGame = Task::query()->where('key', 'shared.long_game')->firstOrFail();

    // The sentence was moved, not rewritten. Nothing here may drift into a
    // figure or a deadline that no human reviewed.
    expect($citizenship->description)->toContain('5 years + B1 + Einbürgerungstest, €255')
        ->and($citizenship->description)->toContain('abolished in October 2025')
        // And it must not have been left behind in the card it came from.
        ->and($longGame->description)->not->toContain('Einbürgerungstest');
});
