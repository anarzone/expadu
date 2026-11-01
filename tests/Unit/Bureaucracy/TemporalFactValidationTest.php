<?php

use App\Bureaucracy\Facts\FactRegistry;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('date facts reject impossible partial and relative dates', function (mixed $value) {
    expect(fn () => (new FactRegistry)->definition('visa_expires_at')->normalize($value))->toThrow(DomainException::class);
})->with(['2026-02-30', '2026-09-1', 'tomorrow', true, 1]);

test('a historical permit start cannot be recorded in the future', function () {
    expect(fn () => (new FactRegistry)->definition('family_residence_permit_held_since')->normalize('2026-09-09'))
        ->toThrow(DomainException::class);
});

test('valid historical and expiry dates retain their distinct meanings', function () {
    $registry = new FactRegistry;
    expect($registry->definition('family_residence_permit_held_since')->normalize('2026-09-08'))->toBe('2026-09-08')
        ->and($registry->definition('family_residence_permit_held_since')->normalize('2024-02-29'))->toBe('2024-02-29')
        ->and($registry->definition('visa_expires_at')->normalize('2028-02-29'))->toBe('2028-02-29')
        ->and($registry->definition('visa_expires_at')->normalize('2026-01-01'))->toBe('2026-01-01');
});

test('confirmed counts are nonnegative integers and household answers are real booleans', function (string $key, mixed $value) {
    expect(fn () => (new FactRegistry)->definition($key)->normalize($value))->toThrow(DomainException::class);
})->with([
    ['weekly_work_hours', -1], ['weekly_work_hours', '20'], ['blue_card_qualifying_months', 21.5],
    ['marital_household_continues', 'false'], ['marital_household_continues', 1],
]);
