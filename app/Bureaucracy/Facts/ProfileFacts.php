<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\User;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Paperwork answers whose value lives on the account holder's profile, its one source (owner,
 * 27 Oct 2026: "German level should be stored in profile only"). Paperwork reads the value from
 * the profile and a value given in Paperwork is saved there. "Not sure" or "Rather not say" has
 * no value, so the record keeps only that, as it does for any answer; anyone else in the record
 * has no profile, so theirs stay Paperwork answers.
 */
final class ProfileFacts
{
    /** The fact's key => its users column. */
    public const KEYS = ['german_level' => 'german_level'];

    /** The profile behind this person's answers: theirs when they're the account holder, else none. */
    public static function owner(BureaucracyPerson $person): ?User
    {
        return $person->account_user_id === null ? null : User::query()->find($person->account_user_id);
    }

    /**
     * The view with the profile's answers in place of anything the record kept for them.
     *
     * @param  array{revision: int, values: array, states: array, evidence: array}  $view
     * @return array{revision: int, values: array, states: array, evidence: array}
     */
    public static function apply(array $view, BureaucracyCase $case, FactRegistry $registry): array
    {
        $person = $case->person_id === null ? null : BureaucracyPerson::query()->find($case->person_id);
        $user = $person === null ? null : self::owner($person);
        if ($user === null) {
            return $view;
        }
        foreach (self::KEYS as $key => $column) {
            $recorded = $view['states'][$key] ?? null;
            unset($view['values'][$key]);
            $value = $user->{$column} instanceof BackedEnum ? $user->{$column}->value : $user->{$column};
            if ($value === null) {
                // No level on the profile: only the record's own "not sure" or "rather not say" stands.
                if (! in_array($recorded, ['unknown', 'declined', 'not_applicable'], true)) {
                    unset($view['states'][$key], $view['evidence'][$key]);
                }

                continue;
            }
            $view['values'][$key] = $registry->definition($key)->normalize($value);
            $view['states'][$key] = 'value';
            $view['evidence'][$key] = ['fact_id' => null, 'source' => 'profile', 'context_id' => "profile:{$column}",
                'effective_from' => null, 'effective_until' => null, 'end_date_unknown' => false];
        }

        return $view;
    }

    /**
     * After the profile changed one of these, Paperwork's own after-change steps for the account
     * holder's record, as an answer written in Paperwork takes (its revision, snapshots, consents).
     *
     * @param  list<string>  $columns
     */
    public static function profileChanged(User $user, array $columns): void
    {
        $keys = array_keys(array_intersect(self::KEYS, $columns));
        if ($keys === []) {
            return;
        }
        DB::transaction(function () use ($user, $keys): void {
            $person = BureaucracyPerson::query()->where('account_user_id', $user->id)->lockForUpdate()->first();
            $case = $person === null ? null
                : BureaucracyCase::query()->where('person_id', $person->id)->where('status', 'active')->lockForUpdate()->first();
            if ($case !== null) {
                app(AfterFactsChanged::class)->record($person, $case, $keys);
            }
        });
    }
}
