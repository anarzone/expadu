<?php

namespace Expadu\CatalogueReview;

use App\Support\OfficialEventSchedule;
use Carbon\CarbonImmutable;

final class EventSnapshotQualification
{
    public const SNAPSHOT_SHA256 = 'd63ecd9d0e704c69edf755319929d62fcad7285a2069d048c837629091157268';

    public const CAPTURED_AT = '2026-10-05T13:15:15.322382+00:00';

    public static function qualify(array $record, CarbonImmutable $now, callable $insideCity): array
    {
        $reasons = [];
        $uid = preg_match('~^https?://www\.stadt-koeln\.de/leben-in-koeln/veranstaltungen/daten/(\d+)/index\.html$~', (string) ($record['link'] ?? ''), $identity)
            ? $identity[1] : null;
        if ($uid === null || trim((string) ($record['title'] ?? '')) === '') {
            $reasons[] = 'invalid_source_identity';
        }

        $dates = [];
        foreach (['beginndatum', 'endedatum'] as $field) {
            $value = $record[$field] ?? null;
            try {
                $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
                    ? CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Europe/Berlin') : null;
                $dates[$field] = $date?->format('Y-m-d') === $value ? $date : null;
            } catch (\Throwable) {
                $dates[$field] = null;
            }
        }
        $startDate = $dates['beginndatum'];
        if ($startDate === null || $dates['endedatum'] === null) {
            $reasons[] = 'invalid_date';
        } elseif (! $startDate->isSameDay($dates['endedatum'])) {
            $reasons[] = 'multi_date_schedule';
        }

        $startsAt = $endsAt = null;
        $clock = OfficialEventSchedule::clockRange($record['uhrzeit'] ?? null);
        if ($clock !== null) {
            if ($startDate !== null) {
                $startsAt = $startDate->setTime((int) $clock['sh'], (int) ($clock['sm'] ?? 0));
                $endsAt = $startDate->setTime((int) $clock['eh'], (int) ($clock['em'] ?? 0));
                if ($endsAt->lessThanOrEqualTo($startsAt)) {
                    $endsAt = $endsAt->addDay();
                }
                if ($endsAt->lessThanOrEqualTo($now)) {
                    $reasons[] = 'already_ended';
                }
            }
        } else {
            $reasons[] = 'ambiguous_time';
        }

        if (! in_array(mb_strtolower(trim((string) ($record['ort'] ?? ''))), ['köln', 'koeln'], true)
            || trim((string) ($record['veranstaltungsort'] ?? '')) === '') {
            $reasons[] = 'not_cologne_venue';
        }
        $lat = filter_var(trim((string) ($record['latitude'] ?? '')), FILTER_VALIDATE_FLOAT);
        $lng = filter_var(trim((string) ($record['longitude'] ?? '')), FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) {
            $reasons[] = 'invalid_coordinates';
        } elseif (! $insideCity((float) $lat, (float) $lng)) {
            $reasons[] = 'outside_city';
        }

        return [
            'source_uid' => $uid, 'eligible' => $reasons === [], 'reasons' => $reasons,
            'starts_at' => $startsAt?->toIso8601String(), 'ends_at' => $endsAt?->toIso8601String(),
        ];
    }
}
