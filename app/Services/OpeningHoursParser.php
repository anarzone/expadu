<?php

namespace App\Services;

/**
 * Best-effort parser for the OSM `opening_hours` tag into a per-weekday
 * structure the composer can read for feasibility. It deliberately handles
 * only the common weekday/time forms — the bulk of Cologne's OSM data — and
 * returns null for the exotic ones (seasonal/month ranges, "sunset",
 * free-text comments). Callers treat null as "assume open within the window"
 * rather than storing hours we can't trust.
 *
 * Output shape: ['mon' => [['08:00', '18:00']], 'tue' => [], ...]. A day
 * mapped to an empty array is closed that day.
 *
 * Closing dates ("Jan 01,Aug 15-Sep 14,Dec 24,25,31 off") don't hide the
 * week they come with: the week is parsed without them, and closedOn() says
 * whether a date is one of them. A "||" fallback that is only a comment is
 * dropped the same way.
 */
class OpeningHoursParser
{
    /** @var list<string> */
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @var array<string, int> */
    private const MONTHS = ['Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4, 'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8, 'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12];

    /** @var array<string, int> */
    private const DAY_INDEX = [
        'Mo' => 0, 'Tu' => 1, 'We' => 2, 'Th' => 3, 'Fr' => 4, 'Sa' => 5, 'Su' => 6,
    ];

    /**
     * @return array<string, list<array{0: string, 1: string}>>|null
     */
    public static function parse(?string $raw): ?array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        if ($raw === '24/7') {
            return array_fill_keys(self::DAYS, [['00:00', '24:00']]);
        }
        $raw = self::week($raw);
        if ($raw === '') {
            return null;
        }

        // Gate: once day codes, HH:MM times, "off" and separators are stripped,
        // anything left (month names, "sunset", German free-text, comments)
        // means a form we don't trust — bail rather than store wrong hours.
        if (! self::isParseable($raw)) {
            return null;
        }

        $week = array_fill_keys(self::DAYS, []);
        $matched = false;

        // ";" separates rules where a later one overrides earlier ones for the
        // days it names; "," before a day selector adds a rule ("Mo-Fr
        // 11:30-14:30, Th 17:30-20:00" keeps both Thursday intervals).
        foreach (preg_split('/\s*;\s*/', $raw) as $rule) {
            $additive = false;
            foreach (preg_split('/(?<=\d)\s*,\s*(?=(?:Mo|Tu|We|Th|Fr|Sa|Su|PH)\b)/', trim((string) $rule)) as $part) {
                $parsed = self::parseRule(trim((string) $part));
                if ($parsed === null) {
                    continue;
                }

                [$days, $intervals] = $parsed;
                foreach ($days as $d) {
                    $week[self::DAYS[$d]] = $additive && $intervals !== []
                        ? [...$week[self::DAYS[$d]], ...$intervals]
                        : $intervals;
                }
                $matched = true;
                $additive = true;
            }
        }

        return $matched ? $week : null;
    }

    /**
     * One rule: its weekdays and intervals (an empty list = closed). Public
     * holidays (PH) aren't modelled, so a PH selector is dropped and a
     * holiday-only rule is skipped; a rule without a weekday selector applies
     * to every day.
     *
     * @return array{0: list<int>, 1: list<array{0: string, 1: string}>}|null
     */
    private static function parseRule(string $rule): ?array
    {
        if ($rule === '') {
            return null;
        }

        $isOff = (bool) preg_match('/\boff\b/i', $rule);
        if ($isOff) {
            $rule = trim((string) preg_replace('/\boff\b/i', '', $rule));
        }

        $hasHoliday = (bool) preg_match('/\bPH\b/', $rule);
        $rule = trim((string) preg_replace('/\s*,?\s*\bPH\b\s*,?\s*/', ' ', $rule));
        $rule = trim((string) preg_replace('/^,|,$/', '', $rule));

        // "<days> <times>" — the space between the two is optional in the wild;
        // a bare "<times>" means every day.
        if (! preg_match('/^([A-Za-z][A-Za-z,\- ]*?)?\s*(\d[\d:,\-]*)?$/', $rule, $m) || $rule === '') {
            return null;
        }

        $daySpec = trim($m[1] ?? '');
        if ($daySpec === '') {
            if ($hasHoliday) {
                return null; // a holiday-only rule
            }
            $days = range(0, 6);
        } else {
            $days = self::expandDays($daySpec);
            if ($days === null) {
                return null;
            }
        }

        if ($isOff || ! isset($m[2]) || $m[2] === '') {
            return $daySpec === '' && ! $isOff ? null : [$days, []];
        }

        $intervals = self::parseTimes($m[2]);

        return $intervals === null ? null : [$days, $intervals];
    }

    /**
     * Whether the place's hours list this date as closed: one of its closing dates
     * ("Dec 24,25,31 off", "Aug 15-Sep 14 off").
     */
    public static function closedOn(?string $raw, \DateTimeInterface $date): bool
    {
        $day = [(int) $date->format('n'), (int) $date->format('j')];
        foreach (self::closingRules((string) $raw) as $rule) {
            $month = null;
            foreach (explode(',', (string) preg_replace('/\s*\boff\b\s*$/i', '', $rule)) as $item) {
                // "Aug 15-Sep 14", "Dec 24", "25" (the month before it), "Dec 24-26".
                if (preg_match('/^\s*(?:([A-Z][a-z]{2})\s+)?(\d{1,2})(?:\s*-\s*(?:([A-Z][a-z]{2})\s+)?(\d{1,2}))?\s*$/', $item, $m) !== 1) {
                    continue;
                }
                $month = $m[1] !== '' ? (self::MONTHS[$m[1]] ?? null) : $month;
                if ($month === null) {
                    continue;
                }
                $from = [$month, (int) $m[2]];
                $to = isset($m[4]) && $m[4] !== '' ? [($m[3] ?? '') !== '' ? (self::MONTHS[$m[3]] ?? $month) : $month, (int) $m[4]] : $from;
                $inside = $from <= $to ? ($day >= $from && $day <= $to) : ($day >= $from || $day <= $to);
                if ($inside) {
                    return true;
                }
                $month = $to[0];
            }
        }

        return false;
    }

    /** The weekly part: without its closing dates and a fallback that is only a comment. */
    private static function week(string $raw): string
    {
        $parts = explode('||', $raw, 2);
        $raw = isset($parts[1]) && preg_match('/^\s*"[^"]*"\s*$/u', $parts[1]) === 1 ? $parts[0] : $raw;

        return trim(implode('; ', array_filter(array_map(fn (string $rule): string => self::split($rule)[0], preg_split('/\s*;\s*/', $raw) ?: []))));
    }

    /**
     * Its closing-date rules ("Jan 01,Aug 15-Sep 14,Dec 24,25,31 off"): a rule, or the end of one
     * ("Tu off, Jan 01,… off"), that starts with a month and says off.
     *
     * @return list<string>
     */
    private static function closingRules(string $raw): array
    {
        return array_values(array_filter(array_map(fn (string $rule): string => self::split($rule)[1], preg_split('/\s*;\s*/', explode('||', $raw, 2)[0]) ?: [])));
    }

    /**
     * A rule as its weekly part and its closing dates.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $rule): array
    {
        $months = implode('|', array_keys(self::MONTHS));

        return preg_match('/^(.*?)(?:^|,)\s*((?:'.$months.')\b[^;]*\boff)\s*$/', trim($rule), $m) === 1
            ? [trim($m[1]), trim($m[2])] : [trim($rule), ''];
    }

    private static function isParseable(string $raw): bool
    {
        $residue = preg_replace('/(Mo|Tu|We|Th|Fr|Sa|Su|PH)/', '', $raw);
        $residue = preg_replace('/\d{1,2}:\d{2}/', '', (string) $residue);
        $residue = preg_replace('/off/i', '', (string) $residue);
        $residue = preg_replace('#[\s,;:\-.|/]#', '', (string) $residue);

        return $residue === '';
    }

    /**
     * @return list<int>|null weekday indices (0 = Mon … 6 = Sun)
     */
    private static function expandDays(string $spec): ?array
    {
        $days = [];

        foreach (explode(',', $spec) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (isset(self::DAY_INDEX[$token])) {
                $days[] = self::DAY_INDEX[$token];

                continue;
            }

            if (preg_match('/^(Mo|Tu|We|Th|Fr|Sa|Su)-(Mo|Tu|We|Th|Fr|Sa|Su)$/', $token, $m)) {
                $start = self::DAY_INDEX[$m[1]];
                $end = self::DAY_INDEX[$m[2]];
                for ($i = $start; ; $i = ($i + 1) % 7) {
                    $days[] = $i;
                    if ($i === $end) {
                        break;
                    }
                }

                continue;
            }

            return null; // unknown day token → don't trust this rule
        }

        return array_values(array_unique($days));
    }

    /**
     * @return list<array{0: string, 1: string}>|null
     */
    private static function parseTimes(string $spec): ?array
    {
        $intervals = [];

        foreach (explode(',', $spec) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (! preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})$/', $token, $m)) {
                return null;
            }

            $openHour = (int) $m[1];
            $openMin = (int) $m[2];
            $closeHour = (int) $m[3];
            $closeMin = (int) $m[4];
            if ($openHour > 24 || $closeHour > 24 || $openMin > 59 || $closeMin > 59) {
                return null;
            }

            $intervals[] = [
                sprintf('%02d:%02d', $openHour, $openMin),
                sprintf('%02d:%02d', $closeHour, $closeMin),
            ];
        }

        return $intervals === [] ? null : $intervals;
    }
}
