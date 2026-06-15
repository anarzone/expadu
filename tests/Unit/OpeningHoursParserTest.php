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
