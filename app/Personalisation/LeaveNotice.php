<?php

namespace App\Personalisation;

use App\Models\User;
use App\Models\UserPlace;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Noticing when they leave Home or Work (the canvas "When you leave", owner 18 Nov 2026: "build
 * location"). The phone watches two circles only, their Home and Work, and tells the server two
 * things, never a coordinate:
 * - leaving one, on a day and at a time their routine runs: the next way there, in words, for the
 *   phone to show at once ("Heading to work? Your tram, line 12, leaves … in 4 minutes.");
 * - a trip from one to the other: kept like a trip started in the app (Routines), marked as noticed,
 *   so routines learn from trips they didn't start here.
 * Its own yes (SETTING), only with routine learning on; off erases what it noticed.
 */
final class LeaveNotice
{
    public const SETTING = 'leave_notice';

    public const PLACES = ['home', 'work'];

    /** A routine runs from this long before its usual time … */
    private const EARLY_MINUTES = 30;

    /** … until this long after it. */
    private const LATE_MINUTES = 60;

    public function __construct(private RouteService $routes) {}

    public static function on(User $user): bool
    {
        return Routines::learns($user) && $user->setting(self::SETTING) === true;
    }

    /**
     * What to tell them as they leave `$from`, or null when no routine of theirs runs now.
     *
     * @return array{title: string, body: string, link: string}|null
     */
    public function leaving(User $user, string $from, CarbonImmutable $now): ?array
    {
        if (! self::on($user) || ! in_array($from, self::PLACES, true)) {
            return null;
        }
        $now = $now->setTimezone('Europe/Berlin');
        $start = $this->place($user, $from);
        if ($start === null) {
            return null;
        }
        $routine = collect(app(Routines::class)->confirmed($user))
            ->filter(fn (array $r) => $r['remind'] && in_array($now->dayOfWeekIso, $r['weekdays'], true) && ($r['from_key'] ?? $from) === $from)
            ->first(function (array $r) use ($now): bool {
                $usual = $now->setTimeFromTimeString($r['at']);

                return $now->greaterThanOrEqualTo($usual->subMinutes(self::EARLY_MINUTES)) && $now->lessThanOrEqualTo($usual->addMinutes(self::LATE_MINUTES));
            });
        $to = $routine === null ? null : $this->destination($user, (int) $routine['id']);
        if ($routine === null || $to === null) {
            return null;
        }
        $journey = collect($this->routes->withTimeBudget(4)->plan($start, $to, $now, 3, false)->journeys)
            ->first(fn (Journey $j) => $j->departAt->greaterThanOrEqualTo($now->subMinute()));
        if ($journey === null) {
            return null;
        }
        $label = self::spoken((string) $routine['to_label']);

        return ['title' => "Heading to {$label}?", 'body' => self::words($journey, $now, $label), 'link' => '/travel'];
    }

    /**
     * A trip the phone noticed, from one of their places to the other, kept like one they started.
     */
    public function noticed(User $user, string $from, string $to, CarbonImmutable $leftAt): bool
    {
        if (! self::on($user) || $from === $to || ! in_array($from, self::PLACES, true) || ! in_array($to, self::PLACES, true)) {
            return false;
        }
        $place = UserPlace::query()->where('user_id', $user->id)->where('category', $to)->first(['name', 'category']);
        if ($place === null) {
            return false;
        }
        $at = $leftAt->setTimezone('Europe/Berlin');
        DB::table('trip_history')->insert([
            'subject_key' => Pseudonym::for($user),
            'to_key' => "place:{$to}",
            'to_label' => $place->name ?: ucfirst($to),
            'from_key' => $from,
            'started_at' => $at->format('Y-m-d H:i:sP'),
            'weekday' => $at->dayOfWeekIso,
            'minute' => $at->hour * 60 + $at->minute,
            'source' => 'noticed',
        ]);

        return true;
    }

    /** Turned off: the trips it noticed go; the ones started in the app stay. */
    public function forget(User|int $user): void
    {
        DB::table('trip_history')->where('subject_key', Pseudonym::for($user))->where('source', 'noticed')->delete();
    }

    /** "Your tram, line 12, leaves Zülpicher Platz in 4 minutes. You'll be at Mediapark by 08:31." */
    public static function words(Journey $journey, CarbonImmutable $now, string $label): string
    {
        $arrive = $journey->arriveAt->setTimezone('Europe/Berlin')->format('H:i');
        $ride = collect($journey->legs)->first(fn ($leg) => $leg->isTransit());
        if ($ride === null) {
            return "It’s a {$journey->durationMin}-minute walk. You’ll be there by {$arrive}.";
        }
        $minutes = max(0, (int) floor(($ride->departAt->getTimestamp() - $now->getTimestamp()) / 60));
        $when = $minutes === 0 ? 'now' : ($minutes === 1 ? 'in 1 minute' : "in {$minutes} minutes");
        $kind = match ($ride->mode) {
            'bus' => 'bus',
            'rail' => 'train',
            'ferry' => 'ferry',
            default => 'tram',
        };
        $line = $ride->lineName !== null ? "{$kind}, line {$ride->lineName}," : $kind;

        return "Your {$line} leaves {$ride->from->name} {$when}. You’ll be at {$label} by {$arrive}.";
    }

    /** Home and Work said as people say them; any other place by its name. */
    private static function spoken(string $label): string
    {
        return in_array(mb_strtolower($label), ['home', 'work'], true) ? mb_strtolower($label) : $label;
    }

    private function place(User $user, string $category): ?GeoPoint
    {
        $place = UserPlace::query()->where('user_id', $user->id)->where('category', $category)->whereNotNull('lat')->whereNotNull('lng')->first(['lat', 'lng']);

        return $place === null ? null : new GeoPoint((float) $place->lat, (float) $place->lng);
    }

    /** Where a routine goes: their Home or Work, or the point its key keeps (~100 m). */
    private function destination(User $user, int $routineId): ?GeoPoint
    {
        $key = (string) DB::table('routines')->where('id', $routineId)->value('to_key');
        if (preg_match('/^place:(home|work)$/', $key, $m) === 1) {
            return $this->place($user, $m[1]);
        }
        if (preg_match('/^at:(-?\d+\.\d+),(-?\d+\.\d+)$/', $key, $m) === 1) {
            return new GeoPoint((float) $m[1], (float) $m[2]);
        }

        return null;
    }
}
