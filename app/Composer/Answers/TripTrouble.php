<?php

namespace App\Composer\Answers;

use App\Services\DisruptionService;
use App\Transit\NearbyStation;
use Throwable;

/**
 * A major problem on a trip's way, for Composer's trip answer to lead with. It follows
 * the delays canvas the owner agreed, 22 Oct 2026: everyday delays stay small and in
 * place, and one headline goes to a major line problem.
 *
 * "Major" is the classified data's own call, never the model's. Two sources:
 * - a notice NoticeScale grades major, on a line the trip rides or at its From or To
 *   stop, worded by its checked English brief, with KVB's German behind it;
 * - live trouble at the stop where the trip boards (NearbyStation's rules: most of
 *   the hour's runs cancelled, or runs in a row 15+ min late), worded here from its
 *   own numbers.
 * Nothing else is said, and nothing is invented.
 */
final class TripTrouble
{
    public function __construct(private DisruptionService $disruptions, private NearbyStation $stations) {}

    /**
     * @param  array<string, mixed>  $journey  a journey as the trip answer carries it (lines without run numbers)
     * @param  string|null  $city  the city a stop's name starts with ("Köln"), left off as the stop signs do
     * @return array{title: string, text: ?string, source: 'notice'|'live', details: ?string}|null
     */
    public function major(array $journey, ?string $city): ?array
    {
        $rides = collect($journey['legs'] ?? [])->filter(fn (mixed $leg): bool => is_array($leg) && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true))->values();
        if ($rides->isEmpty()) {
            return null;
        }
        $stop = fn (?string $name): string => trim((string) preg_replace('/^'.preg_quote((string) $city, '/').'\s+/u', '', (string) $name));
        $lines = $rides->pluck('line')->filter()->map(fn ($line): string => (string) $line)->unique()->values()->all();
        $ends = array_values(array_filter([$stop($rides->first()['from']['name'] ?? null), $stop($rides->last()['to']['name'] ?? null)]));

        return $this->notice($lines, $ends) ?? $this->live($rides->first(), $stop($rides->first()['from']['name'] ?? null));
    }

    /**
     * A major notice on one of the trip's lines or at its From or To stop.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $ends
     * @return array{title: string, text: ?string, source: 'notice', details: ?string}|null
     */
    private function notice(array $lines, array $ends): ?array
    {
        try {
            $notice = collect($this->disruptions->getLineDisruptions())->first(fn (array $d): bool => ($d['scale'] ?? 'minor') === 'major'
                && (array_intersect(array_map('strval', $d['affected_lines'] ?? []), $lines) !== []
                    || array_intersect(array_map(fn ($s): string => mb_strtolower((string) $s), $d['affected_stops'] ?? []), array_map('mb_strtolower', $ends)) !== []));
        } catch (Throwable) {
            return null;
        }
        if ($notice === null) {
            return null;
        }
        $german = trim($notice['title']."\n".($notice['description'] ?? ''));

        // Its checked English brief; without one, its own words, as Travel shows it.
        return is_array($notice['brief'] ?? null)
            ? ['title' => (string) $notice['brief']['headline'], 'text' => $notice['brief']['text'] ?: null, 'source' => 'notice', 'details' => $german]
            : ['title' => (string) $notice['title'], 'text' => ($notice['description'] ?? '') !== '' ? $notice['description'] : null, 'source' => 'notice', 'details' => null];
    }

    /**
     * Live trouble on the first line the trip boards, at the stop where it boards it.
     *
     * @param  array<string, mixed>  $ride
     * @return array{title: string, text: ?string, source: 'live', details: null}|null
     */
    private function live(array $ride, string $boarding): ?array
    {
        if ($boarding === '' || ! filled($ride['line'] ?? null)) {
            return null;
        }
        try {
            $trouble = collect($this->stations->named($boarding)['trouble'] ?? [])->first(fn (array $t): bool => (string) $t['line'] === (string) $ride['line']);
        } catch (Throwable) {
            return null;
        }
        if ($trouble === null) {
            return null;
        }
        $towards = $trouble['dest'] !== '' ? " towards {$trouble['dest']}" : '';
        if ($trouble['kind'] === 'cancelled') {
            return ['title' => "Line {$trouble['line']}: {$trouble['count']} of the next {$trouble['of']} runs cancelled",
                'text' => "That’s by the live times at {$boarding}{$towards}. Travel can find you another way.", 'source' => 'live', 'details' => null];
        }
        $delays = array_column($trouble['runs'], 'delay');
        $late = min($delays) === max($delays) ? min($delays).' min' : min($delays).'–'.max($delays).' min';

        return ['title' => "Line {$trouble['line']} is running {$late} late",
            'text' => "The next {$trouble['count']} runs{$towards} at {$boarding} are each 15 min or more late, by live times. Allow extra time, or let Travel find another way.",
            'source' => 'live', 'details' => null];
    }
}
