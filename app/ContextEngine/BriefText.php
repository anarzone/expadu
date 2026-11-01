<?php

namespace App\ContextEngine;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A brief's words (owner, 26 Oct 2026): the most important items first, each in its own line,
 * at most three and how many more. The AI may rank, merge and write them instead (BriefWriter),
 * from the items' own facts only, each line checked; when it can't, or a line doesn't hold, these.
 */
final class BriefText
{
    /** About what a push shows before it's cut. */
    public const LINES = 3;

    public function __construct(private BriefWriter $writer) {}

    /**
     * @param  list<array{id: string, kind: string, text: string, facts: string, own: bool, rank: int}>  $items  most important first (DayBriefs::items)
     * @return array{title: string, lines: list<string>, by: string}
     */
    public function write(User $user, array $items, CarbonImmutable $day, string $kind): array
    {
        $title = $kind === DayBriefs::EVENING ? 'Tomorrow, '.$day->isoFormat('dddd') : 'Your '.$day->isoFormat('dddd');
        $written = $this->writer->write($user, $items, $day, $kind);
        if ($written !== null) {
            return ['title' => $title, 'lines' => $written, 'by' => 'ai'];
        }

        return ['title' => $title, 'lines' => self::template($items), 'by' => 'template'];
    }

    /**
     * Each item's own line, most important first, then how many more there are.
     *
     * @param  list<array{text: string}>  $items
     * @return list<string>
     */
    public static function template(array $items): array
    {
        $lines = array_slice(array_column($items, 'text'), 0, self::LINES);
        $more = count($items) - count($lines);

        return $more > 0 ? [...$lines, "And {$more} more in Expadu."] : $lines;
    }
}
