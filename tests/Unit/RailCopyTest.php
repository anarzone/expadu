<?php

use App\Home\RailCopy;
use Carbon\CarbonImmutable;

/** A Monday (dayOfWeek 1) used as the default "now" for most cases. */
function monday(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-06-15'); // Monday
}

test('rain leads the title with an indoor framing', function () {
    [$title, $subline] = RailCopy::leadRail(monday(), rain: true, isWeekend: false, isEvening: false, isNewArrival: false);

    expect($title)->toContain('Monday')
        ->and(strtolower($title))->toMatch('/dry|indoor|cosy/')
        ->and($subline)->toBe('indoor picks, ranked for you');
});

test('a weekend window gets weekend copy when it is not raining', function () {
    $saturday = CarbonImmutable::parse('2026-06-13'); // Saturday

    [$title, $subline] = RailCopy::leadRail($saturday, rain: false, isWeekend: true, isEvening: false, isNewArrival: false);

    expect($title)->toContain('Saturday')
        ->and($subline)->toBe('ranked for your weekend');
});

test('a weekday evening gets evening copy', function () {
    [$title, $subline] = RailCopy::leadRail(monday(), rain: false, isWeekend: false, isEvening: true, isNewArrival: false);

    expect($title)->toContain('Monday')
        ->and($subline)->toBe('good for this evening');
});

test('a just-arrived user gets an orientation framing on a plain day', function () {
    [$title, $subline] = RailCopy::leadRail(monday(), rain: false, isWeekend: false, isEvening: false, isNewArrival: true);

    expect($title)->toContain('Cologne')
        ->and($subline)->toBe('a gentle intro to the city');
});

test('the plain default names the weekday and ranks for you', function () {
    [$title, $subline] = RailCopy::leadRail(monday(), rain: false, isWeekend: false, isEvening: false, isNewArrival: false);

    expect($title)->toContain('Monday')
        ->and($subline)->toBe('ranked for you');
});

test('rain wins over weekend and evening and over a new arrival', function () {
    [, $subline] = RailCopy::leadRail(monday(), rain: true, isWeekend: true, isEvening: true, isNewArrival: true);

    expect($subline)->toBe('indoor picks, ranked for you');
});

test('the title is deterministic for the same inputs', function () {
    $a = RailCopy::leadRail(monday(), rain: false, isWeekend: false, isEvening: false, isNewArrival: false);
    $b = RailCopy::leadRail(monday(), rain: false, isWeekend: false, isEvening: false, isNewArrival: false);

    expect($a)->toBe($b);
});

test('the default copy rotates across the week instead of one fixed string', function () {
    $titles = collect(range(0, 6))
        ->map(fn (int $offset) => RailCopy::leadRail(
            CarbonImmutable::parse('2026-06-15')->addDays($offset),
            rain: false, isWeekend: false, isEvening: false, isNewArrival: false,
        )[0])
        ->unique();

    // More than one phrasing appears over a week — not the old single template.
    expect($titles->count())->toBeGreaterThan(1);
});
