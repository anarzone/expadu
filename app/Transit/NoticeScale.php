<?php

namespace App\Transit;

use Carbon\CarbonImmutable;

/**
 * Whether a transit notice is a major line problem (a headline) or an
 * everyday one (small, in place), for the owner's rule (22 Oct 2026): "we
 * must not show them with big message cards … major issues with a line get
 * big headline messages". Worked out in code from the notice's own words and
 * dates, never by the model, with the reasons so the page can say why.
 *
 * Major: the line (or a stretch of it) doesn't run: suspended or closed,
 * replacement buses, or split into two; or a diversion for three days or
 * more. Everything else is minor: a short diversion, a stop moved, delays,
 * a lift, a single cancelled trip.
 */
final class NoticeScale
{
    /** A notice running this long is "long". */
    public const LONG_DAYS = 3;

    /** Reasons that make a notice major on their own. */
    private const MAJOR = ['suspended', 'replacement', 'split'];

    /** @var array<string, string> reason => pattern over the notice's German words */
    private const PATTERNS = [
        // A line, track or tunnel closed; a road or a station entrance closed isn't.
        'suspended' => '/\b(eingestellt|unterbrochen|unterbrechung|streckenunterbrechung|vollsperrung|streckensperrung|streik)\b|sperrung\s+(der|des)\s+(strecke|gleise?|(stadtbahn|u-bahn)?tunnels?)|(strecke|tunnel|gleise?|abschnitt)\S*\s+(ist|sind|wird|werden)?\s*gesperrt|kein(en)?\s+(zug|bahn|bus|straßenbahn|stadtbahn)?verkehr|(verkehr|verkehren|fahren|fährt)\s+(dort\s+)?(keine|nicht)\b|\bentf(ä|ae)ll(t|en)\s+(komplett|ganz|vollständig)/iu',
        'replacement' => '/ersatzverkehr|schienenersatz|ersatzbus|\bsev\b/iu',
        'split' => '/\btrennung\b|\bgetrennt\b|in\s+zwei\s+(teil|abschnitt)/iu',
        'diverted' => '/umleitung|umgeleitet|\bfahren\s+eine\s+umleitung/iu',
        'stop_moved' => '/\bverlegt\b|ersatzhaltestelle|nicht\s+angefahren|entfällt\s+die\s+haltestelle|haltestellenausfall|haltestellenausfälle/iu',
        'delays' => '/verspätung|verzögerung|verzögerungen/iu',
    ];

    /**
     * @return array{scale: 'major'|'minor', reasons: list<string>}
     */
    public static function of(
        string $title,
        ?string $details,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $until = null,
        ?string $severity = null,
    ): array {
        $text = $title.' '.(string) $details;
        $reasons = [];
        foreach (self::PATTERNS as $reason => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $reasons[] = $reason;
            }
        }
        if ($from !== null && $until !== null && $from->diffInDays($until) >= self::LONG_DAYS) {
            $reasons[] = 'long';
        }
        // The KVB scraper's own words-based "critical" (closures, strikes).
        if ($severity === 'critical' && ! in_array('suspended', $reasons, true)) {
            $reasons[] = 'suspended';
        }

        $major = array_intersect($reasons, self::MAJOR) !== []
            || (in_array('diverted', $reasons, true) && in_array('long', $reasons, true));

        return ['scale' => $major ? 'major' : 'minor', 'reasons' => $reasons];
    }
}
