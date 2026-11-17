<?php

namespace App\Personalisation;

use App\Composer\TasteSignals;
use App\Models\User;
use App\Models\UserPlace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Routines learned from trips (track, week 3; the plan's "B. It learns the routine"): the trips
 * they start, kept for 90 days with personalisation on, and the routine they make. The same place
 * three times in three weeks, at about the same time (within half an hour), is offered, never
 * assumed ("You often go to Work around 08:10 on weekdays"); a confirmed one is theirs to change,
 * pause or forget, and tells them when to leave. Nothing of where a trip started is kept but
 * whether that was their Home or Work. Its own opt-in (the plan's Decisions: asked when they turn on
 * routine learning), off by default and only with personalisation on; never a health service, a
 * place of worship, an LGBTQ+ venue or a sensitive event (Sensitive), whatever the trip.
 */
final class Routines
{
    /** The setting that turns it on (UserSetting, off by default). */
    public const SETTING = 'routine_learning';

    /** How long a started trip is kept. */
    public const KEEP_DAYS = 90;

    /** What's looked at for a routine: the last three weeks, three days in it at least. */
    public const WINDOW_DAYS = 21;

    public const TIMES = 3;

    /** About the same time: within half an hour either side. */
    private const SPREAD_MINUTES = 30;

    /** Within this of their Home or Work, a trip set off from there. */
    private const NEAR_METRES = 300;

    /** Not now: offered again after this long. */
    public const DISMISS_DAYS = 30;

    /**
     * A trip they started, kept with personalisation on.
     *
     * @param  array{name: string, lat: float|int, lng: float|int}  $destination
     * @param  array{lat?: float|int|null, lng?: float|int|null}|null  $origin
     */
    public function started(User $user, array $destination, ?array $origin, CarbonImmutable $at, ?array $target = null): void
    {
        if (! self::learns($user) || $this->sensitive($destination, $target)) {
            return;
        }
        $at = $at->setTimezone('Europe/Berlin');
        $to = $this->placeAt($user, (float) $destination['lat'], (float) $destination['lng']);
        DB::table('trip_history')->insert([
            'subject_key' => Pseudonym::for($user),
            'to_key' => $to !== null ? "place:{$to->category}" : self::key((float) $destination['lat'], (float) $destination['lng']),
            'to_label' => $to !== null ? ($to->name ?: ucfirst($to->category)) : mb_substr((string) $destination['name'], 0, 200),
            'from_key' => isset($origin['lat'], $origin['lng']) ? $this->placeAt($user, (float) $origin['lat'], (float) $origin['lng'])?->category : null,
            'started_at' => self::at($at),
            'weekday' => $at->dayOfWeekIso,
            'minute' => $at->hour * 60 + $at->minute,
        ]);
    }

    /**
     * The routine to offer now, if there is one: the strongest the trips show that isn't one
     * already, nor turned down lately.
     *
     * With the trips it rests on, so the phone can show them what it saw: each a day and a time, every
     * trip to that place in the window (the ones that didn't fit too), and nothing of where it started.
     *
     * @return array{to_key: string, to_label: string, from_key: ?string, weekdays: list<int>, at: string, times: int, trips: list<array{date: string, weekday: int, at: string}>}|null
     */
    public function offer(User $user, CarbonImmutable $now): ?array
    {
        if (! self::learns($user)) {
            return null;
        }
        $subject = Pseudonym::for($user);
        $taken = DB::table('routines')->where('subject_key', $subject)
            ->where(fn ($q) => $q->whereNull('dismissed_until')->orWhere('dismissed_until', '>', self::at($now)))->pluck('to_key')->all();
        $trips = DB::table('trip_history')->where('subject_key', $subject)
            ->where('started_at', '>=', self::at($now->subDays(self::WINDOW_DAYS)))->whereNotIn('to_key', $taken)
            ->orderBy('minute')->get(['to_key', 'to_label', 'from_key', 'started_at', 'weekday', 'minute']);

        $offer = $trips->groupBy('to_key')->map(fn ($group) => self::cluster($group->values()->all()))
            ->filter()->sortByDesc('times')->first();
        if ($offer === null) {
            return null;
        }

        return [...$offer, 'trips' => $trips->where('to_key', $offer['to_key'])
            ->map(fn (object $t): array => ['date' => CarbonImmutable::parse($t->started_at)->setTimezone('Europe/Berlin')->toDateString(),
                'weekday' => (int) $t->weekday, 'at' => sprintf('%02d:%02d', intdiv((int) $t->minute, 60), (int) $t->minute % 60)])
            ->sortBy(fn (array $t): string => $t['date'].' '.$t['at'])->values()->all()];
    }

    /**
     * The biggest set of trips to one place within half an hour of each other, on three days at least.
     *
     * @param  list<object>  $trips  one place's, by minute
     * @return array{to_key: string, to_label: string, from_key: ?string, weekdays: list<int>, at: string, times: int}|null
     */
    private static function cluster(array $trips): ?array
    {
        $best = [];
        $bestDays = 0;
        foreach ($trips as $i => $first) {
            $within = array_values(array_filter(array_slice($trips, $i), fn (object $t): bool => $t->minute - $first->minute <= 2 * self::SPREAD_MINUTES));
            $days = collect($within)->map(fn (object $t): string => CarbonImmutable::parse($t->started_at)->setTimezone('Europe/Berlin')->toDateString())->unique()->count();
            if ($days >= self::TIMES && $days > $bestDays) {
                [$best, $bestDays] = [$within, $days];
            }
        }
        if ($best === []) {
            return null;
        }
        $minutes = collect($best)->pluck('minute')->sort()->values();
        $median = (int) (round($minutes[intdiv($minutes->count(), 2)] / 5) * 5);
        $last = end($best);

        return [
            'to_key' => $last->to_key, 'to_label' => $last->to_label,
            'from_key' => collect($best)->pluck('from_key')->filter()->countBy()->sortDesc()->keys()->first(),
            'weekdays' => collect($best)->pluck('weekday')->unique()->sort()->values()->map(fn ($d): int => (int) $d)->all(),
            'at' => sprintf('%02d:%02d', intdiv($median, 60), $median % 60),
            'times' => collect($best)->map(fn (object $t): string => CarbonImmutable::parse($t->started_at)->setTimezone('Europe/Berlin')->toDateString())->unique()->count(),
        ];
    }

    /**
     * Their routines, confirmed.
     *
     * @return list<array{id: int, to_label: string, from_key: ?string, weekdays: list<int>, at: string, remind: bool}>
     */
    public function confirmed(User $user): array
    {
        return DB::table('routines')->where('subject_key', Pseudonym::for($user))->whereNull('dismissed_until')->orderBy('at')
            ->get()->map(fn (object $r): array => ['id' => (int) $r->id, 'to_label' => (string) $r->to_label, 'from_key' => $r->from_key,
                'weekdays' => array_map('intval', json_decode((string) $r->weekdays, true) ?: []), 'at' => (string) $r->at, 'remind' => (bool) $r->remind])
            ->all();
    }

    /**
     * Yes to an offer, as it is or with the days and time they changed.
     *
     * @param  list<int>  $weekdays
     */
    public function confirm(User $user, array $offer, array $weekdays, string $at): ?int
    {
        if (! self::learns($user)) {
            return null;
        }
        $this->keep(Pseudonym::for($user), $offer, ['weekdays' => json_encode(array_values($weekdays)), 'at' => $at, 'remind' => true, 'dismissed_until' => null]);

        return (int) DB::table('routines')->where('subject_key', Pseudonym::for($user))->where('to_key', $offer['to_key'])->value('id');
    }

    /** Learning routines: their own yes, with personalisation on. */
    public static function learns(User $user): bool
    {
        return $user->personalises() && $user->setting(self::SETTING) === true;
    }

    /** A routine's row, kept: its first time it was created stays. */
    private function keep(string $subject, array $offer, array $values): void
    {
        $where = ['subject_key' => $subject, 'to_key' => $offer['to_key']];
        $row = [...$values, 'to_label' => $offer['to_label'], 'from_key' => $offer['from_key'], 'updated_at' => now()];
        if (DB::table('routines')->where($where)->exists()) {
            DB::table('routines')->where($where)->update($row);
        } else {
            DB::table('routines')->insert([...$where, ...$row, 'created_at' => now()]);
        }
    }

    /**
     * Where going says something about a person (Sensitive): its words, an event marked so, or a
     * health service, a place of worship or an LGBTQ+ venue there.
     *
     * @param  array{name: string, lat: float|int, lng: float|int}  $destination
     */
    private function sensitive(array $destination, ?array $target): bool
    {
        $words = trim(((string) ($destination['name'] ?? '')).' '.($target['title'] ?? '').' '.($target['label'] ?? ''));
        if (Memory::sensitive($words)) {
            return true;
        }
        $item = SignalItemType::parse($target['id'] ?? null);
        if ($item !== null && $item[0] === SignalItemType::Event && DB::table('events')->where('id', $item[1])->where('sensitive', true)->exists()) {
            return true;
        }
        $lat = (float) $destination['lat'];
        $lng = (float) $destination['lng'];
        $there = app(TasteSignals::class)->spotAt((string) ($destination['name'] ?? ''), $lat, $lng)?->id;
        [$condition, $bindings] = Sensitive::spotCondition('s');

        // The place there, or any such place within ~40 m: a doctor's door shares an address.
        return DB::table('spots as s')
            ->where(fn ($q) => $q->where('s.id', $there ?? 0)
                ->orWhere(fn ($near) => $near->whereBetween('s.lat', [$lat - 0.00036, $lat + 0.00036])->whereBetween('s.lng', [$lng - 0.00057, $lng + 0.00057])))
            ->whereRaw($condition, $bindings)->exists();
    }

    /**
     * Not now: not offered again for a while. Its id, so Undo can take the no back (forget), or
     * null when that isn't on offer.
     */
    public function dismiss(User $user, string $toKey, CarbonImmutable $now): ?int
    {
        $subject = Pseudonym::for($user);
        $offer = $this->offer($user, $now);
        if ($offer === null || $offer['to_key'] !== $toKey) {
            return null;
        }
        $this->keep($subject, $offer, ['weekdays' => json_encode($offer['weekdays']), 'at' => $offer['at'], 'remind' => false, 'dismissed_until' => self::at($now->addDays(self::DISMISS_DAYS))]);

        return (int) DB::table('routines')->where('subject_key', $subject)->where('to_key', $toKey)->value('id');
    }

    /** @param  array{weekdays?: list<int>, at?: string, remind?: bool}  $changes */
    public function change(User $user, int $id, array $changes): bool
    {
        $values = array_filter(['weekdays' => isset($changes['weekdays']) ? json_encode(array_values($changes['weekdays'])) : null,
            'at' => $changes['at'] ?? null, 'remind' => $changes['remind'] ?? null], fn ($v) => $v !== null);

        return DB::table('routines')->where('id', $id)->where('subject_key', Pseudonym::for($user))->whereNull('dismissed_until')
            ->update([...$values, 'updated_at' => now()]) === 1;
    }

    /** A routine, or a Not now (Undo): gone, and offered again if the trips still show it. */
    public function forget(User $user, int $id): bool
    {
        return DB::table('routines')->where('id', $id)->where('subject_key', Pseudonym::for($user))->delete() === 1;
    }

    /** How many of their trips are kept for routines (What Expadu knows says so). */
    public function tripsKept(User $user): int
    {
        return DB::table('trip_history')->where('subject_key', Pseudonym::for($user))->count();
    }

    /** Their trips, forgotten; the routines they confirmed stay. */
    public function forgetTrips(User $user): int
    {
        return DB::table('trip_history')->where('subject_key', Pseudonym::for($user))->delete();
    }

    /** Everything: trips and routines (personalisation off, the account deleted). */
    public function erase(User|int $user): void
    {
        $subject = Pseudonym::for($user);
        DB::table('trip_history')->where('subject_key', $subject)->delete();
        DB::table('routines')->where('subject_key', $subject)->delete();
    }

    /** Trips older than KEEP_DAYS go (nightly), and turned-down offers once they may be made again. */
    public static function prune(CarbonImmutable $now): int
    {
        return DB::table('trip_history')->where('started_at', '<', self::at($now->subDays(self::KEEP_DAYS)))->delete()
            + DB::table('routines')->whereNotNull('dismissed_until')->where('dismissed_until', '<', self::at($now))->delete();
    }

    /**
     * A moment as the database is given it: with its offset. The query builder writes a time bare,
     * and the database then reads it in its own session's zone, an hour or two off Cologne's when
     * that is UTC: a trip at 23:40 would come back as the next day's.
     */
    private static function at(CarbonImmutable $at): string
    {
        return $at->format('Y-m-d H:i:sP');
    }

    /** Their Home or Work at a point, when it's within ~300 m of one. */
    private function placeAt(User $user, float $lat, float $lng): ?UserPlace
    {
        return UserPlace::query()->where('user_id', $user->id)->whereIn('category', ['home', 'work'])->whereNotNull('lat')->whereNotNull('lng')->get()
            ->first(fn (UserPlace $p): bool => self::metres($lat, $lng, (float) $p->lat, (float) $p->lng) <= self::NEAR_METRES);
    }

    /** A place's key, ~100 m wide: the same café twice is the same key. */
    private static function key(float $lat, float $lng): string
    {
        return sprintf('at:%.3f,%.3f', $lat, $lng);
    }

    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
