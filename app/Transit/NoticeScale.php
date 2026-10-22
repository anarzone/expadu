<?php

namespace App\Transit;

/**
 * Whether a transit notice is a major line problem (a headline) or an
 * everyday one (small, in place), by the delays canvas the owner agreed
 * (22 Oct 2026, the design session's spec). Worked out in code from the
 * notice's own words, never by the model, with the reasons so the page can
 * say why.
 *
 * Major: the line doesn't serve part of its route (suspended, closed track or
 * tunnel, ends early, runs in sections, replacement buses), or a strike. How
 * long it runs doesn't matter. Everything else is minor: a diversion, a stop
 * moved or skipped, delays, a lift. (A line whose runs at a stop are mostly
 * cancelled or very late is major too: NearbyStation::trouble.)
 */
final class NoticeScale
{
    /** Reasons that make a notice major on their own. */
    private const MAJOR = ['suspended', 'cut_short', 'replacement', 'split', 'strike'];

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
     * @return array{scale: 'major'|'minor', reasons: list<string>}
     */
    public static function of(string $title, ?string $details): array
    {
        $text = $title.' '.(string) $details;
        $reasons = [];
        foreach (self::PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $reasons[] = $reason;
            }
        }

        return ['scale' => array_intersect($reasons, self::MAJOR) !== [] ? 'major' : 'minor', 'reasons' => $reasons];
    }
}
