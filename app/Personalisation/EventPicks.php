<?php

namespace App\Personalisation;

use App\Enums\EventCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The kinds of event a person picked themselves ("What would you go to?", owner 17 Nov 2026: "A,
 * keep kids, wording ok, reuse interests"), so Today has an order for them from day one, before
 * what they open, save and go to says more. Kept in `users.interests` as
 * {"kinds": [...], "done": bool}: `done` once they said Done or Not now, so Today stops asking.
 * Personalisation's, like what it learns: only under the wording that names it (WORDING), and
 * erased when it's turned off (SignalRecorder::forget).
 */
final class EventPicks
{
    /** The wording that first names the kinds they pick (PersonalisationWording 4). */
    public const WORDING = 4;

    /** How many Today asks for before it says Done. */
    public const ASK_AT_LEAST = 3;

    /** @return list<string> the kinds they can pick: every event kind but Other */
    public static function kinds(): array
    {
        return array_values(array_map(fn (EventCategory $kind) => $kind->value,
            array_filter(EventCategory::cases(), fn (EventCategory $kind) => $kind !== EventCategory::Other)));
    }

    /**
     * What they picked, and whether they're done picking; null when personalisation isn't on under
     * the wording that names picks.
     *
     * @return array{kinds: list<string>, done: bool}|null
     */
    public function of(User $user): ?array
    {
        if (! PersonalisationWording::covers($user, self::WORDING)) {
            return null;
        }
        $kept = json_decode((string) ($user->getRawOriginal('interests') ?? ''), true);
        $kinds = is_array($kept) && is_array($kept['kinds'] ?? null) ? $kept['kinds'] : [];

        return [
            // Only kinds there still are, in the order they picked them.
            'kinds' => array_values(array_intersect($kinds, self::kinds())),
            'done' => is_array($kept) && ($kept['done'] ?? false) === true,
        ];
    }

    /**
     * The kinds they picked, as Today's leanings name kinds (TasteFacts::categoryLabel).
     *
     * @return list<string>
     */
    public function labels(User $user): array
    {
        return array_values(array_filter(array_map(TasteFacts::categoryLabel(...), $this->of($user)['kinds'] ?? [])));
    }

    /**
     * Their picks as they are now. Refused (null) when personalisation isn't on under its wording.
     *
     * @param  list<string>  $kinds
     * @return array{kinds: list<string>, done: bool}|null
     */
    public function save(User $user, array $kinds, bool $done): ?array
    {
        if (! PersonalisationWording::covers($user, self::WORDING)) {
            return null;
        }
        $kinds = array_values(array_unique(array_intersect($kinds, self::kinds())));
        DB::table('users')->where('id', $user->id)->update(['interests' => json_encode(['kinds' => $kinds, 'done' => $done])]);
        $user->setRawAttributes([...$user->getAttributes(), 'interests' => json_encode(['kinds' => $kinds, 'done' => $done])], true);
        // Today's leanings are read again on the next look.
        TodayTaste::forget($user->id);

        return ['kinds' => $kinds, 'done' => $done];
    }

    /** Gone with the rest of personalisation (SignalRecorder::forget). */
    public function erase(User|int $user): void
    {
        DB::table('users')->where('id', $user instanceof User ? $user->id : $user)->update(['interests' => null]);
        if ($user instanceof User) {
            $user->setRawAttributes([...$user->getAttributes(), 'interests' => null], true);
        }
    }

    /**
     * What Today shows for them: whether to ask, what they picked, whether what they open already
     * says more (`learned`), and each kind with how many are on still today.
     *
     * @param  array<string, int>  $today  events still on today, by kind
     * @return array{ask: bool, picked: list<string>, learned: bool, kinds: list<array{key: string, label: string, today: int}>}|null
     */
    public function forToday(User $user, array $today, bool $learned): ?array
    {
        $picks = $this->of($user);
        if ($picks === null) {
            return null;
        }

        return [
            'ask' => ! $picks['done'],
            'picked' => $picks['kinds'],
            'learned' => $learned,
            'kinds' => array_map(fn (string $key) => ['key' => $key, 'label' => EventCategory::from($key)->label(), 'today' => (int) ($today[$key] ?? 0)], self::kinds()),
        ];
    }
}
