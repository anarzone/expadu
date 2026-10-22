<?php

namespace App\Transit;

/**
 * Whether a transit notice is a major line problem (a headline) or an
 * everyday one (small, in place), by the delays canvas the owner agreed
 * ("Notices + live data", 22 Oct 2026). Worked out in code from the notice's
 * own words, lines and grade, never by the model, with the reasons so the
 * page can say why.
 *
 * Major: the line doesn't serve part of its route (suspended, closed track or
 * tunnel, ends early, runs in sections, replacement buses, skips stops on a
 * diversion); the notice is graded critical or major; it names three or more
 * tram lines at once; or a strike. How long it runs doesn't matter. Everything
 * else is minor: a diversion, a stop moved, delays, a lift. (A line whose runs
 * at a stop are mostly cancelled or very late is major too:
 * NearbyStation::trouble.)
 */
final class NoticeScale
{
    /** Tram lines named at once that make a notice major. */
    public const MANY_LINES = 3;

    /** Reasons that make a notice major on their own. */
    private const MAJOR = ['suspended', 'cut_short', 'replacement', 'split', 'strike', 'graded', 'many_lines'];

    /** @var array<string, string> reason => pattern over the notice's German words */
    private const PATTERNS = [
        // A line, track or tunnel closed; a road ("Vollsperrung Augustastraße") or a station entrance closed isn't.
        'suspended' => '/\b(eingestellt|unterbrochen|unterbrechung|streckenunterbrechung|streckensperrung)\b|sperrung\s+(der|des)\s+(strecke|gleise?|(stadtbahn|u-bahn)?tunnels?)|(strecke|tunnel|gleise?|abschnitt)\S*\s+(ist|sind|wird|werden)?\s*gesperrt|kein(en)?\s+(zug|bahn|bus|straßenbahn|stadtbahn)?verkehr|(verkehr|verkehren|fahren|fährt)\s+(dort\s+)?(keine|nicht)\b|\bentf(ä|ae)ll(t|en)\s+(komplett|ganz|vollständig)/iu',
        // Ends or turns early, or a stop the line can't reach: part of the route isn't served.
        'cut_short' => '/\b(linien?|bahnen|züge|busse)\b[^.]{0,80}\b(enden|endet|wenden|wendet|beginnen|beginnt)\b|\b(nicht|wieder)\s+erreichbar\b/iu',
        'replacement' => '/ersatzverkehr|schienenersatz|ersatzbus|\bsev\b/iu',
        'split' => '/\btrennung\b|\bgetrennt\b|in\s+zwei\s+(teil|abschnitt)/iu',
        'strike' => '/\b(warn)?streiks?\b/iu',
        'diverted' => '/umleitung|umgeleitet/iu',
        'stops_skipped' => '/nicht\s+angefahren|entfällt\s+die\s+haltestelle|haltestellenausf(a|ä)ll/iu',
        'stop_moved' => '/\bverlegt\b|ersatzhaltestelle/iu',
        'delays' => '/verspätung|verzögerung|verzögerungen/iu',
    ];

    /**
     * @param  list<string>  $lines  the lines it names
     * @param  string|null  $severity  its grade (KVB's scraper: critical, major, moderate, minor)
     * @return array{scale: 'major'|'minor', reasons: list<string>}
     */
    public static function of(string $title, ?string $details, array $lines = [], ?string $severity = null): array
    {
        $text = $title.' '.(string) $details;
        $reasons = [];
        foreach (self::PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $reasons[] = $reason;
            }
        }
        if (in_array($severity, ['critical', 'major'], true)) {
            $reasons[] = 'graded';
        }
        // Tram lines are KVB's numbered ones below 100; buses are 100 and up.
        $trams = array_filter($lines, fn ($line) => ctype_digit((string) $line) && (int) $line < 100);
        if (count(array_unique($trams)) >= self::MANY_LINES) {
            $reasons[] = 'many_lines';
        }

        $major = array_intersect($reasons, self::MAJOR) !== []
            || (in_array('diverted', $reasons, true) && in_array('stops_skipped', $reasons, true));

        return ['scale' => $major ? 'major' : 'minor', 'reasons' => $reasons];
    }
}
