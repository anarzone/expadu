<?php

use App\Services\GermanHolidayService;
use Carbon\Carbon;

test('detects fixed national holidays', function () {
    $service = new GermanHolidayService;

    expect($service->getHolidayName(Carbon::parse('2026-01-01')))->toBe('Neujahrstag');
    expect($service->getHolidayName(Carbon::parse('2026-05-01')))->toBe('Tag der Arbeit');
    expect($service->getHolidayName(Carbon::parse('2026-10-03')))->toBe('Tag der Deutschen Einheit');
    expect($service->getHolidayName(Carbon::parse('2026-12-25')))->toBe('1. Weihnachtstag');
    expect($service->getHolidayName(Carbon::parse('2026-12-26')))->toBe('2. Weihnachtstag');
});

test('detects NRW state holidays', function () {
    $service = new GermanHolidayService;

    expect($service->getHolidayName(Carbon::parse('2026-11-01')))->toBe('Allerheiligen');
    expect($service->getHolidayName(Carbon::parse('2026-06-04')))->toBe('Fronleichnam');
});

test('detects easter-based holidays for 2026', function () {
    $service = new GermanHolidayService;

    // Easter 2026 is April 5
    expect($service->getHolidayName(Carbon::parse('2026-04-03')))->toBe('Karfreitag');
    expect($service->getHolidayName(Carbon::parse('2026-04-05')))->toBe('Ostersonntag');
    expect($service->getHolidayName(Carbon::parse('2026-04-06')))->toBe('Ostermontag');
    expect($service->getHolidayName(Carbon::parse('2026-05-14')))->toBe('Christi Himmelfahrt');
    expect($service->getHolidayName(Carbon::parse('2026-05-25')))->toBe('Pfingstmontag');
});

test('returns null for non-holidays', function () {
    $service = new GermanHolidayService;

    expect($service->getHolidayName(Carbon::parse('2026-04-07')))->toBeNull(); // Regular Tuesday
    expect($service->getHolidayName(Carbon::parse('2026-07-15')))->toBeNull(); // Mid-summer
});

test('isHoliday returns boolean correctly', function () {
    $service = new GermanHolidayService;

    expect($service->isHoliday(Carbon::parse('2026-04-06')))->toBeTrue();  // Ostermontag
    expect($service->isHoliday(Carbon::parse('2026-04-07')))->toBeFalse(); // Regular day
});

test('isNonWorkingDay includes weekends and holidays', function () {
    $service = new GermanHolidayService;

    expect($service->isNonWorkingDay(Carbon::parse('2026-04-05')))->toBeTrue();  // Ostersonntag (Sunday + holiday)
    expect($service->isNonWorkingDay(Carbon::parse('2026-04-04')))->toBeTrue();  // Saturday
    expect($service->isNonWorkingDay(Carbon::parse('2026-04-06')))->toBeTrue();  // Ostermontag (Monday + holiday)
    expect($service->isNonWorkingDay(Carbon::parse('2026-04-07')))->toBeFalse(); // Regular Tuesday
});

test('isShopsClosedTomorrow detects sundays and holidays', function () {
    $service = new GermanHolidayService;

    // Saturday before Sunday → shops closed tomorrow
    expect($service->isShopsClosedTomorrow(Carbon::parse('2026-04-11')))->toBeTrue();

    // Thursday before Karfreitag → shops closed tomorrow
    expect($service->isShopsClosedTomorrow(Carbon::parse('2026-04-02')))->toBeTrue();

    // Regular weekday → shops open tomorrow
    expect($service->isShopsClosedTomorrow(Carbon::parse('2026-04-08')))->toBeFalse();

    // Day before Tag der Arbeit → shops closed
    expect($service->isShopsClosedTomorrow(Carbon::parse('2026-04-30')))->toBeTrue();
});

test('handles different years correctly', function () {
    $service = new GermanHolidayService;

    // Easter 2027 is March 28
    expect($service->getHolidayName(Carbon::parse('2027-03-26')))->toBe('Karfreitag');
    expect($service->getHolidayName(Carbon::parse('2027-03-28')))->toBe('Ostersonntag');
    expect($service->getHolidayName(Carbon::parse('2027-03-29')))->toBe('Ostermontag');
});
