<?php

use App\Support\EventTipGuard;

it('drops tips that hedge', function (string $tip) {
    expect(EventTipGuard::grounded($tip, 'Konzert im Park'))->toBeNull();
})->with([
    'It may rain, bring a jacket.',
    'This could get crowded.',
    'It is usually busy after 8.',
    'Often sold out, come early.',
    'Probably family friendly.',
]);

it('drops a named language the listing does not name', function (string $tip, string $source) {
    expect(EventTipGuard::grounded($tip, $source))->toBeNull();
})->with([
    'English with no language in the source' => ['Held in English.', 'Lesung im Literaturhaus'],
    '"Deutschland" is not German' => ['Held in English.', 'Die größte Messe in Deutschland'],
    'English named, only German in source' => ['Talks are in English.', 'Vortrag auf Deutsch'],
    'no language at all' => ['No German needed.', '5 km laufen, jeden Samstag.'],
]);

it('keeps a tip whose language claim the listing backs, and plain practical tips', function (string $tip, string $source) {
    expect(EventTipGuard::grounded($tip, $source))->toBe($tip);
})->with([
    'English backed' => ['The talk is in English.', 'Talk in English, free entry'],
    'German backed' => ['The tour is in German.', 'Führung auf Deutsch'],
    'practical' => ['Bring your parkrun barcode.', 'parkrun im Rheinpark'],
]);
