<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

function personaKey(int $index = 0): string
{
    return BureaucracyPersonas::demo()[$index]['key'];
}

test('becoming a persona wipes the previous persona instead of layering on it', function () {
    $user = User::factory()->onboarded()->create([
        'is_admin' => true,
        'situation' => 'student',
        'bureaucracy_path' => 'student_non_eu',
        // `stale_marker` belongs to no persona, so if it survives the switch
        // the reset is layering rather than replacing.
        'profile_attributes' => ['qa_persona' => 'stale-persona', 'stale_marker' => 'leftover'],
    ]);

    // Progress + a hand-answered fact from the PREVIOUS run. Neither is
    // owned by the scenario synchronizer, so both used to survive a switch.
    $task = Task::factory()->create();
    $staleProgress = UserTask::factory()->create(['user_id' => $user->id, 'task_id' => $task->id]);
    $case = BureaucracyCase::create(['user_id' => $user->id, 'status' => 'active']);
    BureaucracyCaseFact::create([
        'case_id' => $case->id,
        'key' => 'purpose',
        'value' => 'study',
        'state' => 'confirmed',
        'source' => 'onboarding',
    ]);

    $this->actingAs($user)->post('/qa/become/'.personaKey())->assertRedirect();

    $user->refresh();

    // No leftovers from the previous persona. The generator legitimately
    // recreates rows for tasks that also apply to the new persona, so the
    // assertion is that the old PROGRESS row is gone, not the task.
    expect(UserTask::whereKey($staleProgress->id)->exists())->toBeFalse()
        ->and(BureaucracyCaseFact::where('key', 'purpose')->where('source', 'onboarding')->count())->toBe(0)
        ->and($user->profile_attributes['stale_marker'] ?? null)->toBeNull();

    // And the badge names the persona actually applied.
    expect($user->profile_attributes['qa_persona'] ?? null)->toBe(personaKey());
});

test('answering onboarding by hand clears a stale QA persona badge', function () {
    // The exact prod state that made the corner announce "Non-EU student"
    // while the profile said non-EU employee.
    $user = User::factory()->create([
        'onboarded_at' => null,
        'profile_attributes' => ['qa_persona' => 'neu-student'],
    ]);

    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_planned' => false,
        'arrival_date' => now()->subDays(5)->toDateString(),
        'veedel' => collect(config('veedels', []))->flatten()->first(),
        'entry_mode' => 'd_visa',
        'address_registration_status' => 'unsure',
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->profile_attributes['qa_persona'] ?? null)->toBeNull();
});
