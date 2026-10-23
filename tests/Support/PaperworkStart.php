<?php

namespace Tests\Support;

use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCase;
use App\Models\User;
use App\Onboarding\CompleteBureaucracyOnboarding;
use App\Onboarding\SaveBureaucracyDraft;
use Illuminate\Support\Str;

/** Someone's Paperwork with answers, set up the ways the app records them. */
final class PaperworkStart
{
    /**
     * As the app starts it: the city first (PUT people/self/city), then their
     * own record (POST people/self), then each answer as a recorded fact, in
     * the order given.
     *
     * @param  array<string, mixed>  $answers  fact key => value
     */
    public static function answer(User $user, array $answers, string $city = 'Köln'): BureaucracyCase
    {
        test()->actingAs($user)->putJson('/bureaucracy/v2/people/self/city', ['city' => $city])->assertSuccessful();
        test()->postJson('/bureaucracy/v2/people/self')->assertSuccessful();
        $case = BureaucracyCase::query()->where('user_id', $user->id)->sole();
        foreach ($answers as $key => $value) {
            app(RecordFactChange::class)->execute($user, $case->person, $key, $value, null, $case->fresh()->fact_version);
        }

        return $case->fresh();
    }

    /**
     * Answers confirmed together through the v2 onboarding draft
     * (SaveBureaucracyDraft, CompleteBureaucracyOnboarding): the path that
     * records them as the person's own and retires QA sample answers.
     *
     * @param  array<string, mixed>  $answers  fact key => value
     */
    public static function completeDraft(User $user, array $answers): BureaucracyCase
    {
        $case = app(EnsureAccountHolder::class)->dossier($user);
        $draft = app(SaveBureaucracyDraft::class)->execute($user, $case->person, (string) Str::uuid(), 0, 1,
            array_map(fn (mixed $value): array => ['value' => $value], $answers));
        app(CompleteBureaucracyOnboarding::class)->execute($user, $case->person, $draft->id, $draft->version,
            $case->fact_version, (string) Str::uuid());

        return $case->fresh();
    }
}
