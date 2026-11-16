<?php

namespace App\Day;

use App\ContextEngine\DayBriefs;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Today's one line under the date (track, week 2: "Today speaks from the picture"): the day said
 * from what's on it, in the brief's own words (DayBriefs::items, the one list the brief, push and
 * Today share), so written in code and never by the AI. A busy day says so; a free one says what's
 * on in the city instead. Times are marked, for the phone to set in bold.
 */
final class DayLine
{
    /** From this many things it's a busy one. */
    private const BUSY = 3;

    public function __construct(private DayBriefs $briefs) {}

    /**
     * @return array{text: string, parts: list<array{text: string, strong: bool}>}
     */
    public function for(User $user, CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        // What's still ahead today, most important first.
        $items = collect($this->briefs->items($user, $now->startOfDay(), DayBriefs::MORNING))
            ->reject(fn (array $item): bool => is_string($item['at'] ?? null) && CarbonImmutable::parse($item['at'])->lessThan($now))
            ->values();

        $text = match (true) {
            $items->isEmpty() => $this->free($now),
            $items->count() >= self::BUSY => 'A busy one. '.$items[0]['text'].' And '.($items->count() - 1).' more.',
            $items->count() === 2 => $items[0]['text'].' Then '.lcfirst($items[1]['text']),
            default => $items[0]['text'],
        };

        return ['text' => $text, 'parts' => self::parts($text)];
    }

    /** Nothing of theirs: what's on in the city, from now on. */
    private function free(CarbonImmutable $now): string
    {
        $events = Event::occurringBetween($now, $now->endOfDay());
        if ($events->isEmpty()) {
            return 'Nothing planned today.';
        }
        $first = $events->min(fn (array $o) => $o['starts_at'])->setTimezone('Europe/Berlin');

        return 'Nothing planned. '.$events->count().' '.($events->count() === 1 ? 'event' : 'events').' on, the first at '.$first->max($now)->format('H:i').'.';
    }

    /**
     * The line in pieces, each time ("08:40") its own and marked strong.
     *
     * @return list<array{text: string, strong: bool}>
     */
    public static function parts(string $text): array
    {
        $pieces = preg_split('/(\b(?:[01]\d|2[0-3]):[0-5]\d\b)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$text];

        return array_map(fn (string $piece): array => ['text' => $piece, 'strong' => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $piece) === 1], $pieces);
    }
}
