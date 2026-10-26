<?php

namespace App\Personalisation;

use App\Enums\SpotCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a person has been looking at lately, for Today's builders (owner, 26 Oct 2026: "make it
 * learn"): the kinds of place and the Veedel they asked Composer about or acted on, the stops and
 * lines they picked in Travel, and which of Today's "Needs you" kinds they open or dismiss, ranked
 * by how often, the fresher the more. And Today's order as the AI put it (order(), TodayRank).
 * With personalisation off, under the first wording, or with fewer than MIN_SIGNALS, the defaults:
 * nothing personal, so each builder keeps its own order.
 */
final class TodayTaste
{
    /** Fewer than this many signals in the window says nothing about them yet. */
    public const MIN_SIGNALS = 5;

    /** How far back it looks. */
    private const DAYS = 30;

    /** A signal this many days old counts half. */
    private const HALF_LIFE_DAYS = 14;

    /** What a catalogue signal says they liked (a "Not for me" or an impression says nothing here). */
    private const LIKED = ['detail_viewed', 'saved', 'get_there', 'trip_taken', 'plan_kept_unedited', 'shared', 'more_like_this', 'been_liked'];

    /**
     * @return array{personal: bool, categories: list<string>, areas: list<string>, stops: list<string>, lines: list<string>, needs: array{opened: list<string>, dismissed: list<string>}}
     */
    public function for(User $user): array
    {
        $none = ['personal' => false, 'categories' => [], 'areas' => [], 'stops' => [], 'lines' => [], 'needs' => ['opened' => [], 'dismissed' => []]];
        if (! PersonalisationWording::covers($user, 2)) {
            return $none;
        }
        $now = CarbonImmutable::now();
        $rows = DB::table('user_signals')->where('subject_key', Pseudonym::for($user))->where('created_at', '>=', $now->subDays(self::DAYS)->toIso8601String())
            ->where(fn ($q) => $q->whereIn('item_type', ['topic', 'stop', 'line', 'need'])
                ->orWhere(fn ($q) => $q->whereIn('item_type', ['spot', 'event'])->whereIn('action', self::LIKED)))
            ->get(['action', 'item_type', 'category', 'veedel', 'context', 'created_at']);
        if ($rows->where('action', '!=', 'impression')->count() < self::MIN_SIGNALS) {
            return $none;
        }
        $weight = fn (object $row): float => 0.5 ** ($now->diffInSeconds(CarbonImmutable::parse($row->created_at)) / 86400 / self::HALF_LIFE_DAYS);
        $rank = function (iterable $pairs): array {
            $scores = [];
            foreach ($pairs as [$name, $score]) {
                if (is_string($name) && $name !== '') {
                    $scores[$name] = ($scores[$name] ?? 0) + $score;
                }
            }
            arsort($scores);

            return array_keys($scores);
        };
        $looked = $rows->filter(fn (object $row): bool => in_array($row->item_type, ['topic', 'spot', 'event'], true));
        $transit = fn (string $type): array => $rank($rows->where('item_type', $type)
            ->map(fn (object $row): array => [json_decode((string) $row->context, true)[$type] ?? null, $weight($row)]));
        $needs = fn (array $actions): array => $rank($rows->where('item_type', 'need')->whereIn('action', $actions)->map(fn (object $row): array => [$row->category, $weight($row)]));

        return [
            'personal' => true,
            'categories' => $rank($looked->map(fn (object $row): array => [SpotCategory::tryFrom((string) $row->category)?->value ?? $row->category, $weight($row)])),
            'areas' => $rank($looked->map(fn (object $row): array => [$row->veedel, $weight($row)])),
            'stops' => $transit('stop'),
            'lines' => $transit('line'),
            'needs' => ['opened' => $needs(['tapped', 'kept']), 'dismissed' => $needs(['dismissed', 'undone'])],
        ];
    }

    /**
     * The stops they pick most, for departures (Build session 1): KVB stop names, most first; none
     * below the threshold.
     *
     * @return list<string>
     */
    public function stops(User $user, int $limit = 5): array
    {
        return array_slice($this->for($user)['stops'], 0, $limit);
    }

    /**
     * Today's events and open places in the order the AI put them, with a "why" for some, for today
     * (TodayRank, worked out in the background); null for the builders' own order: the first view of
     * the day, personalisation off, or the AI's reply not holding.
     *
     * @return array{events: list<string>, places: list<string>, why: array<string, string>, at: string}|null
     */
    public function order(User $user): ?array
    {
        $order = Cache::get(self::orderKey($user->id));

        return is_array($order) && PersonalisationWording::covers($user, 2) ? $order : null;
    }

    public static function orderKey(int $userId, ?CarbonImmutable $day = null): string
    {
        return "today_rank:{$userId}:".($day ?? CarbonImmutable::now('Europe/Berlin'))->setTimezone('Europe/Berlin')->toDateString();
    }
}
