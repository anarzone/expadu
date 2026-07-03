<?php

use App\Services\KvbLineColors;

test('S-Bahn lines get the S-Bahn green', function (string $line) {
    expect(KvbLineColors::for($line, 'rail'))->toBe('#008D4B');
})->with(['S6', 'S11', 'S12', 'S19', 'S11A']);

test('regional trains get the rail slate, not the S-Bahn green', function (string $line) {
    expect(KvbLineColors::for($line, 'rail'))->toBe('#455A6B');
})->with(['RB27', 'RE6', 'RRX', 'MRB26']);

test('SchnellBus (SB…) is a bus, not an S-Bahn', function () {
    // "SB40" starts with S but not "S + digit", so it must not read as S-Bahn.
    expect(KvbLineColors::for('SB40', 'bus'))->not->toBe('#008D4B');
});

test('trams keep their brand colours', function () {
    expect(KvbLineColors::for('12'))->toBe('#95C11F');
    expect(KvbLineColors::for('1'))->toBe('#E2001A');
});
