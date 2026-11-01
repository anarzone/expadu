<?php

use App\Services\OpeningHoursParser;

test('parses 24/7 as open every day', function () {
    $r = OpeningHoursParser::parse('24/7');

    expect($r['mon'])->toBe([['00:00', '24:00']]);
    expect($r['sun'])->toBe([['00:00', '24:00']]);
});

test('parses a simple weekday range and closes unmentioned days', function () {
    $r = OpeningHoursParser::parse('Mo-Fr 08:00-18:00');

    expect($r['mon'])->toBe([['08:00', '18:00']]);
    expect($r['fri'])->toBe([['08:00', '18:00']]);
    expect($r['sat'])->toBe([]);
    expect($r['sun'])->toBe([]);
});

test('parses multiple rules with a weekend variant', function () {
    $r = OpeningHoursParser::parse('Mo-Fr 09:00-18:00; Sa,Su 10:00-18:00');

    expect($r['mon'])->toBe([['09:00', '18:00']]);
    expect($r['sat'])->toBe([['10:00', '18:00']]);
    expect($r['sun'])->toBe([['10:00', '18:00']]);
});

test('parses split intervals within a day', function () {
    $r = OpeningHoursParser::parse('Mo-Fr 08:00-12:00,14:00-18:00');

    expect($r['mon'])->toBe([['08:00', '12:00'], ['14:00', '18:00']]);
});

test('handles a glued day-time and an explicit off day', function () {
    $r = OpeningHoursParser::parse('Mo10:00-13:00; Tu off;We-Su 13:00-18:00');

    expect($r['mon'])->toBe([['10:00', '13:00']]);
    expect($r['tue'])->toBe([]);
    expect($r['wed'])->toBe([['13:00', '18:00']]);
    expect($r['sun'])->toBe([['13:00', '18:00']]);
});

test('leaves Monday closed for a Tu-Su venue', function () {
    $r = OpeningHoursParser::parse('Tu-Su 10:00-18:00');

    expect($r['mon'])->toBe([]);
    expect($r['tue'])->toBe([['10:00', '18:00']]);
});

test('returns null for seasonal and month-prefixed ranges', function () {
    expect(OpeningHoursParser::parse('Mar-Oct: 09:00-18:00; Nov-Feb: 09:00-16:30'))->toBeNull();
    expect(OpeningHoursParser::parse('Jan-Apr Mo-Sa 10:00-20:00'))->toBeNull();
});

test('returns null for variable or free-text hours', function () {
    expect(OpeningHoursParser::parse('08:00-sunset'))->toBeNull();
    expect(OpeningHoursParser::parse('closed "Bis auf Weiteres geschlossen."'))->toBeNull();
    expect(OpeningHoursParser::parse(''))->toBeNull();
    expect(OpeningHoursParser::parse(null))->toBeNull();
});

test('closing dates don\'t hide the week they come with, and closedOn reads them', function () {
    // Kolumba's own tag (owner's Sunday plan, 24 Oct 2026: planned at 10:06 as if its hours weren't known).
    $kolumba = 'Mo,We-Su 12:00-17:00; Tu off, Jan 01,Aug 15-Sep 14,Dec 24,25,31 off';

    expect(OpeningHoursParser::parse($kolumba))->toMatchArray(['sun' => [['12:00', '17:00']], 'tue' => []])
        ->and(OpeningHoursParser::closedOn($kolumba, new DateTimeImmutable('2026-10-25')))->toBeFalse()
        ->and(OpeningHoursParser::closedOn($kolumba, new DateTimeImmutable('2026-08-20')))->toBeTrue()
        ->and(OpeningHoursParser::closedOn($kolumba, new DateTimeImmutable('2026-12-31')))->toBeTrue()
        ->and(OpeningHoursParser::closedOn($kolumba, new DateTimeImmutable('2026-12-26')))->toBeFalse()
        ->and(OpeningHoursParser::closedOn('Mo-Fr 09:00-18:00; Dec 24-26 off', new DateTimeImmutable('2026-12-25')))->toBeTrue();
});

test('a fallback that is only a comment is dropped; the week stands', function () {
    $r = OpeningHoursParser::parse('Mo 14:00-21:00; Tu-Th 10:00-21:00; Fr 10:00-18:00;Su, PH off|| "Samstag zweimal im Monat, Details siehe Webseite"');

    expect($r['sun'])->toBe([])->and($r['sat'])->toBe([])->and($r['mon'])->toBe([['14:00', '21:00']]);
});
