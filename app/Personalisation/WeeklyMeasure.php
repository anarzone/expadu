<?php

namespace App\Personalisation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A week of Today's ideas in totals (the track's step 4, owner 17 Nov 2026: "start step 4"): of
 * the ideas the iPhone showed to people with personalisation on, how many were opened (read 10
 * seconds or more), saved or added to a plan, by where they stood, by why they stood there (the
 * arrangement brought them into view, or the usual order had them there anyway; a "New for you";
 * a kind they picked), and by the order's policy. Counts only, never a test: an idea the usual
 * order would have shown but the arrangement pushed out of view could never be tapped, so this
 * can only favour the arrangement. Whether the order helps needs interleaving (the research
 * report), which changes what people see and is the owner's call.
 *
 * Credit: an idea is seen when the phone reports it on screen (impression, Today, positions 0–3),
 * once a day; an open, save, plan-add or Getting there of the same event by the same person later
 * that day is credited to it. Its list is that person's latest Today list first seen before it.
 * Only totals are kept, nothing per person.
 */
final class WeeklyMeasure
{
    /** Fewer people than this and the week says "too few to say" (the research report's smallest readable row). */
    public const ENOUGH_PEOPLE = 30;

    /** What counts as acting on an idea. */
    private const ACTED = ['detail_viewed' => 'opened', 'saved' => 'saved', 'added_to_plan' => 'planned', 'get_there' => 'planned'];

    /** On screen on the iPhone: Ideas draws the first four. */
    private const VISIBLE = 4;

    /**
     * @return array<string, mixed>
     */
    public function for(CarbonImmutable $weekStart): array
    {
        $from = $weekStart->setTimezone('Europe/Berlin')->startOfWeek();
        $to = $from->addWeek();
        $day = fn (string $at): string => CarbonImmutable::parse($at)->setTimezone('Europe/Berlin')->toDateString();

        $seen = DB::table('user_signals')->where('action', 'impression')->where('item_type', 'event')->where('surface', 'today')
            ->whereBetween('position', [0, self::VISIBLE - 1])
            ->where('created_at', '>=', $from->toIso8601String())->where('created_at', '<', $to->toIso8601String())
            ->orderBy('created_at')->get(['subject_key', 'item_id', 'position', 'category', 'created_at'])
            // Once a day each: a re-drawn list doesn't count twice.
            ->unique(fn (object $row) => $row->subject_key.'|'.$row->item_id.'|'.$day($row->created_at))->values();

        $acts = DB::table('user_signals')->whereIn('action', array_keys(self::ACTED))->where('item_type', 'event')
            ->where('created_at', '>=', $from->toIso8601String())->where('created_at', '<', $to->toIso8601String())
            ->get(['subject_key', 'item_id', 'action', 'created_at'])
            ->groupBy(fn (object $row) => $row->subject_key.'|'.$row->item_id.'|'.$day($row->created_at));

        $lists = DB::table('list_impressions')->where('surface', 'today_events')
            ->whereIn('subject_key', $seen->pluck('subject_key')->unique()->all())
            ->whereBetween('day', [$from->toDateString(), $to->subDay()->toDateString()])
            ->orderBy('first_seen_at')->get(['subject_key', 'day', 'policy', 'version', 'baseline', 'shown', 'first_seen_at'])
            ->groupBy(fn (object $row) => $row->subject_key.'|'.$row->day);

        $picks = $this->picksBySubject();
        $blank = fn (): array => ['shown' => 0, 'opened' => 0, 'saved' => 0, 'planned' => 0, 'acted' => 0];
        $report = [
            'positions' => array_fill(1, self::VISIBLE, $blank()),
            'brought_into_view' => $blank(),
            'there_anyway' => $blank(),
            'same_as_usual' => $blank(),
            'new_for_you' => $blank(),
            'picked_kind' => $blank(),
            'policies' => [],
        ];

        foreach ($seen as $row) {
            $date = $day($row->created_at);
            $done = collect($acts->get($row->subject_key.'|'.$row->item_id.'|'.$date, []))
                ->filter(fn (object $act) => self::at($act->created_at) >= self::at($row->created_at))
                ->map(fn (object $act) => self::ACTED[$act->action])->unique()->values();
            $add = function (array $bucket) use ($done): array {
                $bucket['shown']++;
                foreach ($done as $what) {
                    $bucket[$what]++;
                }
                $bucket['acted'] += $done->isEmpty() ? 0 : 1;

                return $bucket;
            };

            $report['positions'][$row->position + 1] = $add($report['positions'][$row->position + 1]);
            if (in_array($row->category, $picks[$row->subject_key] ?? [], true)) {
                $report['picked_kind'] = $add($report['picked_kind']);
            }

            $list = $this->listAt($lists->get($row->subject_key.'|'.$date, collect()), $row->created_at);
            if ($list === null) {
                continue;
            }
            $policy = $list->policy.' '.$list->version;
            $report['policies'][$policy] = $add($report['policies'][$policy] ?? $blank());
            $shown = json_decode($list->shown, true) ?: [];
            $baseline = json_decode($list->baseline, true) ?: [];
            $entry = collect($shown)->first(fn (array $item) => str_starts_with((string) ($item['key'] ?? ''), 'e'.$row->item_id.'@'));
            if (($entry['pick'] ?? null) === 'new') {
                $report['new_for_you'] = $add($report['new_for_you']);
            }
            $usual = array_slice($baseline, 0, self::VISIBLE);
            $where = match (true) {
                array_slice(array_column($shown, 'key'), 0, self::VISIBLE) === $usual => 'same_as_usual',
                $entry !== null && in_array($entry['key'], $usual, true) => 'there_anyway',
                default => 'brought_into_view',
            };
            $report[$where] = $add($report[$where]);
        }

        $people = $seen->pluck('subject_key')->unique()->count();
        $report['positions'] = array_values(array_map(fn (array $bucket, int $at) => ['position' => $at, ...$bucket], $report['positions'], array_keys($report['positions'])));
        ksort($report['policies']);

        return [
            'week' => $from->toDateString(),
            'people' => $people,
            'ideas_shown' => $seen->count(),
            'enough' => $people >= self::ENOUGH_PEOPLE,
            'says' => $people >= self::ENOUGH_PEOPLE
                ? 'Counts only, not a test: an idea the usual order would have shown but the arrangement pushed out of view could never be tapped, so this favours the arrangement.'
                : "Too few to say: {$people} ".($people === 1 ? 'person' : 'people').' this week; it needs '.self::ENOUGH_PEOPLE.' or more.',
            ...$report,
        ];
    }

    /** The person's latest Today list first seen at or before the idea was. */
    private function listAt(Collection $lists, string $at): ?object
    {
        return $lists->filter(fn (object $list) => self::at($list->first_seen_at) <= self::at($at))->last() ?? $lists->first();
    }

    private static function at(string $time): int
    {
        return CarbonImmutable::parse($time)->getTimestamp();
    }

    /**
     * The kinds each person picked, by their ledger pseudonym (EventPicks).
     *
     * @return array<string, list<string>>
     */
    private function picksBySubject(): array
    {
        $picks = [];
        User::query()->whereNotNull('interests')->select(['id', 'interests'])->chunkById(500, function ($users) use (&$picks): void {
            foreach ($users as $user) {
                $kept = json_decode((string) $user->getRawOriginal('interests'), true);
                if (is_array($kept['kinds'] ?? null) && $kept['kinds'] !== []) {
                    $picks[Pseudonym::for($user)] = $kept['kinds'];
                }
            }
        });

        return $picks;
    }
}
