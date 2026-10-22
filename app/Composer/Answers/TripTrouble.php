<?php

namespace App\Composer\Answers;

use App\Transit\Dto\Journey;
use App\Transit\TripDisruptions;
use Throwable;

/**
 * A major problem on a trip's way, for Composer's trip answer to lead with. It follows the
 * delays canvas the owner agreed, 22 Oct 2026: everyday delays stay small and in place,
 * and one headline goes to a major line problem.
 *
 * It uses Travel's own rule (TripDisruptions), so Composer and Plan a trip agree:
 * - a notice NoticeScale grades major, on a line the trip rides or on one serving its
 *   From or To stop, even when the way avoids that line (the 1, 7 and 9 split is why
 *   the way is a bus);
 * - else live trouble on a line it rides, at the stop where it boards.
 *
 * Its words are the classified data's own: a notice's checked English brief, with KVB's
 * German behind it, or live trouble worded here from its numbers. Nothing is invented.
 */
final class TripTrouble
{
    public function __construct(private TripDisruptions $disruptions) {}

    /**
     * @param  array<string, mixed>  $journey  a journey as the trip answer carries it
     * @param  bool  $boards  whether there's time to read the boarding stop's live board
     * @return array{title: string, text: ?string, todo: ?string, source: 'notice'|'live', details: ?string}|null
     */
    public function major(array $journey, ?string $city, bool $boards = true): ?array
    {
        try {
            $trip = Journey::fromArray($journey);
            if ($trip->legs === []) {
                return null;
            }
            $found = $this->disruptions->for([$trip], $trip->legs[0]->from->point, $trip->legs[array_key_last($trip->legs)]->to->point, $boards);
        } catch (Throwable) {
            return null;
        }
        $ridden = array_map('strval', $trip->lines());

        // A notice on the lines it rides first, then one on a line at its From or To.
        $notice = collect($found['notices'])->where('scale', 'major')->sortBy(fn (array $n): int => $n['on'] === 'ways' ? 0 : 1)->first();
        if ($notice !== null) {
            $german = trim($notice['title']."\n".($notice['details'] ?? ''));
            $avoided = array_values(array_diff(array_map('strval', $notice['lines']), $ridden));
            // What to do: only what the way itself shows, that it doesn't use those lines.
            $todo = $notice['on'] === 'ends' && $avoided !== []
                ? 'This way doesn’t use '.(count($avoided) === 1 ? "line {$avoided[0]}" : 'lines '.self::list($avoided)).'.' : null;

            return is_array($notice['brief'] ?? null)
                ? ['title' => (string) $notice['brief']['headline'], 'text' => $notice['brief']['text'] ?: null, 'todo' => $todo, 'source' => 'notice', 'details' => $german]
                : ['title' => (string) $notice['title'], 'text' => $notice['details'] ?? null, 'todo' => $todo, 'source' => 'notice', 'details' => null];
        }

        $trouble = $found['trouble'][0] ?? null;
        if ($trouble === null) {
            return null;
        }
        $stop = trim((string) preg_replace('/^'.preg_quote((string) $city, '/').'\s+/u', '', (string) ($trouble['stop'] ?? '')));
        $towards = $trouble['dest'] !== '' ? " towards {$trouble['dest']}" : '';
        $at = $stop !== '' ? " at {$stop}" : '';
        if ($trouble['kind'] === 'cancelled') {
            return ['title' => "Line {$trouble['line']}: {$trouble['count']} of the next {$trouble['of']} runs cancelled",
                'text' => "That’s by the live times{$at}{$towards}.", 'todo' => 'Travel can find you another way.', 'source' => 'live', 'details' => null];
        }
        $delays = array_column($trouble['runs'], 'delay');
        $late = min($delays) === max($delays) ? min($delays).' min' : min($delays).'–'.max($delays).' min';

        return ['title' => "Line {$trouble['line']} is running {$late} late",
            'text' => "The next {$trouble['count']} runs{$towards}{$at} are each 15 min or more late, by live times.",
            'todo' => 'Allow extra time, or let Travel find another way.', 'source' => 'live', 'details' => null];
    }

    /** @param  list<string>  $lines  "1, 7 and 9" */
    private static function list(array $lines): string
    {
        return count($lines) < 2 ? implode('', $lines) : implode(', ', array_slice($lines, 0, -1)).' and '.end($lines);
    }
}
