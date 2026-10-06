<?php

use Carbon\CarbonImmutable;
use Expadu\CatalogueReview\EventSnapshotQualification;

require_once __DIR__.'/../../../docs/reviews/2026-10-05-catalogue/events-local-qualification.php';

function localOfficialRecord(array $overrides = []): array
{
    return array_replace([
        'link' => 'https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/39393/index.html',
        'title' => 'Wissenschaft im Rathaus',
        'beginndatum' => '2026-10-05', 'endedatum' => '2026-10-05',
        'uhrzeit' => 'von 18:30 bis 19:30', 'veranstaltungsort' => 'Rathaus - Spanischer Bau',
        'ort' => 'Köln', 'latitude' => '50.9382118', 'longitude' => '6.9590346',
    ], $overrides);
}

test('the local replay qualifies a real explicit source range inside the city', function () {
    $result = EventSnapshotQualification::qualify(localOfficialRecord(), CarbonImmutable::parse('2026-10-05T13:15:15Z'), fn () => true);

    expect($result['eligible'])->toBeTrue()
        ->and($result['source_uid'])->toBe('39393')
        ->and($result['starts_at'])->toBe('2026-10-05T18:30:00+02:00')
        ->and($result['ends_at'])->toBe('2026-10-05T19:30:00+02:00');
});

test('the local replay holds schedules and locations it cannot substantiate', function (array $overrides, bool $inside, string $reason) {
    $result = EventSnapshotQualification::qualify(localOfficialRecord($overrides), CarbonImmutable::parse('2026-10-05T13:15:15Z'), fn () => $inside);

    expect($result['eligible'])->toBeFalse()->and($result['reasons'])->toContain($reason);
})->with([
    'multiple sessions' => [['uhrzeit' => '15 bis 16:30 Uhr, 16:30 bis 18 Uhr'], true, 'ambiguous_time'],
    'invented end would be needed' => [['uhrzeit' => '18 Uhr'], true, 'ambiguous_time'],
    'multi-date opening hours' => [['endedatum' => '2026-10-08'], true, 'multi_date_schedule'],
    'already ended' => [['uhrzeit' => '10 bis 11 Uhr'], true, 'already_ended'],
    'invalid date' => [['beginndatum' => '2026-02-31', 'endedatum' => '2026-02-31'], true, 'invalid_date'],
    'outside boundary' => [[], false, 'outside_city'],
    'missing coordinate' => [['latitude' => null], true, 'invalid_coordinates'],
    'online' => [['ort' => null, 'veranstaltungsort' => 'Online-Veranstaltung'], true, 'not_cologne_venue'],
    'foreign source' => [['link' => 'https://example.com/39393'], true, 'invalid_source_identity'],
]);

test('the local replay keeps an overnight event as one source occurrence', function () {
    $result = EventSnapshotQualification::qualify(localOfficialRecord(['uhrzeit' => '18 bis 1 Uhr']), CarbonImmutable::parse('2026-10-05T13:15:15Z'), fn () => true);

    expect($result['eligible'])->toBeTrue()->and($result['ends_at'])->toBe('2026-10-06T01:00:00+02:00');
});
