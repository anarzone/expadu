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
 */
class OpeningHoursParser
{
    /** @var list<string> */
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

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

        // Gate: once day codes, HH:MM times, "off" and separators are stripped,
        // anything left (month names, "sunset", German free-text, comments)
        // means a form we don't trust — bail rather than store wrong hours.
        if (! self::isParseable($raw)) {
            return null;
        }

        $week = array_fill_keys(self::DAYS, []);
        $matched = false;

        foreach (preg_split('/\s*;\s*/', $raw) as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }

            $isOff = (bool) preg_match('/\boff\b/i', $rule);
            if ($isOff) {
                $rule = trim((string) preg_replace('/\boff\b/i', '', $rule));
            }

            // "<days> <times>" — the space between the two is optional in the wild.
            if (! preg_match('/^([A-Za-z][A-Za-z,\- ]*?)\s*(\d[\d:,\-]*)?$/', $rule, $m)) {
                continue;
            }

            $days = self::expandDays($m[1]);
            if ($days === null) {
                continue;
            }

            if ($isOff || ! isset($m[2]) || $m[2] === '') {
                foreach ($days as $d) {
                    $week[self::DAYS[$d]] = [];
                }
                $matched = true;

                continue;
            }

            $intervals = self::parseTimes($m[2]);
            if ($intervals === null) {
                continue;
            }

            foreach ($days as $d) {
                $week[self::DAYS[$d]] = $intervals;
            }
            $matched = true;
        }

        return $matched ? $week : null;
    }

    private static function isParseable(string $raw): bool
    {
        $residue = preg_replace('/(Mo|Tu|We|Th|Fr|Sa|Su)/', '', $raw);
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
